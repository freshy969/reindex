<?php

//! @file ImportCommand.php
//! @brief This file contains the ImportCommand class.
//! @details
//! @author Filippo F. Fadda


namespace PitPress\Console\Command;


use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use PitPress\Model\Blog\Article;
use PitPress\Model\Blog\Book;
use PitPress\Model\Tag\Tag;
use PitPress\Model\User\User;
use PitPress\Model\Blog\Tutorial;
use PitPress\Model\Reply;
use PitPress\Model\Accessory\Star;
use PitPress\Model\Accessory\Classification;
use PitPress\Model\Accessory\Subscription;
use PitPress\Helper\Text;

use Converter\BBCodeConverter;
use Converter\HTMLConverter;

use ElephantOnCouch\Generator\UUID;


//! @brief Imports into CouchDB the data from Programmazione.it v6.4 MySQL database.
//! @nosubgrouping
//! @todo: Download and save images as article attachments.
//! @todo: Save attachments.
//! @todo: Convert [center][/center] to Markdown.
//! @todo: Convert quotes.
//! @todo: Import questions.
//! @todo: Import answers.
class ImportCommand extends AbstractCommand {

  const ARTICLE_DRAFT = 0;
  const ARTICLE = 2;

  const INFORMATIVE = 1;
  const ERROR = 3;
  const DOWNLOAD = 133;

  const BOOK_DRAFT = 10;
  const BOOK = 11;

  const DISCUSSION_DRAFT = 30;
  const DISCUSSION = 31;

  private $limit;

  private $mysql;
  private $couch;
  private $redis;
  private $markdown;

  private $input;
  private $output;


  //! @brief Imports users.
  private function importUsers() {
    $this->output->writeln("Importing users...");

    //$sql = "SELECT idMember, name AS firstName, surname AS lastName, nickName AS displayName, email, password, sex, birthDate AS birthday, ipAddress, confirmHash AS confirmationHash, confirmed AS authenticated, regDate AS creationDate, lastUpdate, avatarData, avatarType, realNamePcy FROM Member";
    $sql = "SELECT id, name AS firstName, surname AS lastName, nickName AS displayName, email, password, sex, UNIX_TIMESTAMP(birthDate) AS birthday, ipAddress, confirmHash AS confirmationHash, confirmed, UNIX_TIMESTAMP(regDate) AS creationDate, lastUpdate, realNamePcy FROM Member";
    $sql .= $this->limit;

    $result = mysqli_query($this->mysql, $sql) or die(mysqli_error($this->mysql));

    $rows = mysqli_num_rows($result);
    $progress = $this->getApplication()->getHelperSet()->get('progress');
    $progress->start($this->output, $rows);

    while ($item = mysqli_fetch_object($result)) {
      $user = new User();

      $user->id = $item->id;
      $user->firstName = iconv('LATIN1', 'UTF-8', $item->firstName);
      $user->lastName = iconv('LATIN1', 'UTF-8', $item->lastName);
      $user->displayName = iconv('LATIN1', 'UTF-8', $item->displayName);
      $user->email = iconv('LATIN1', 'UTF-8', $item->email);
      $user->password = iconv('LATIN1', 'UTF-8', $item->password);
      $user->birthday = (int)$item->birthday;
      $user->sex = $item->sex;
      $user->internetProtocolAddress = iconv('LATIN1', 'UTF-8', $item->ipAddress);
      $user->creationDate = (int)$item->creationDate;
      $user->confirmationHash = iconv('LATIN1', 'UTF-8', $item->confirmationHash);

      if ($item->confirmed == 1)
        $user->confirm();

      $this->couch->saveDoc($user);

      $progress->advance();
    }

    mysqli_free_result($result);

    $progress->finish();
  }


  //! @brief Imports articles.
  private function importArticles() {
    $this->output->writeln("Importing articles...");

    $sql = "SELECT idItem, I.id AS id, M.id AS userId, contributorName, I.title, body, UNIX_TIMESTAMP(date) AS unixTime, hitNum, downloadNum, locked FROM Item I LEFT OUTER JOIN Member M USING (idMember) WHERE (stereotype = ".self::ARTICLE.") ORDER BY date DESC";
    $sql .= $this->limit;

    $result = mysqli_query($this->mysql, $sql) or die(mysqli_error($this->mysql));

    $rows = mysqli_num_rows($result);
    $progress = $this->getApplication()->getHelperSet()->get('progress');
    $progress->start($this->output, $rows);

    while ($item = mysqli_fetch_object($result)) {
      $article = new Article();

      $article->id = $item->id;
      $article->publishingDate = (int)$item->unixTime;
      $article->title = iconv('LATIN1', 'UTF-8', $item->title);

      if (isset($item->userId)) {
        $article->userId = $item->userId;
        $article->username = NULL;
      }
      elseif (!empty($item->contributorName)) {
        $article->userId = NULL;
        $article->username = iconv('LATIN1', 'UTF-8', $item->contributorName);
      }
      else {
        $article->userId = NULL;
        $article->username = NULL;
      }

      $article->body = iconv('LATIN1', 'UTF-8', $item->body);

      // Converts from HTML to BBCode!
      $converter = new HTMLConverter($article->body, $item->id);
      $article->body = $converter->toBBCode();

      // Converts from BBCode to Markdown!
      $converter = new BBCodeConverter($article->body, $item->id);
      $article->body = $converter->toMarkdown();

      try {
        $article->html = $this->markdown->parse($article->body);
      }
      catch(\Exception $e) {
        $this->monolog->addCritical(sprintf(" %d - %s", $item->idItem, $article->title));
      }

      $purged = Text::purge($article->html);
      $article->excerpt = Text::truncate($purged);

      // We finally save the article.
      try {
        //$this->couch->saveDoc($article);
        $article->save();
      }
      catch(\Exception $e) {
        $this->monolog->addCritical($e);
        $this->monolog->addCritical(sprintf("Invalid JSON: %d - %s", $item->idItem, $article->title));
      }

      // We update the article views.
      $this->redis->hSet($article->id, 'hits', $item->hitNum);

      // We update the article downloads.
      if ($item->downloadNum > 0)
        $this->redis->hSet($article->id, 'downloads', $item->downloadNum);

      $progress->advance();
    }

    mysqli_free_result($result);

    $progress->finish();
  }


  //! @brief Imports tutorials.
  private function importTutorials() {
    $this->output->writeln("Importing tutorials...");

    $sql = "SELECT correlationCode, title, UNIX_TIMESTAMP(date) AS unixTime, contributorName, id AS userId FROM Item WHERE (stereotype = ".self::ARTICLE.") GROUP BY correlationCode HAVING COUNT(correlationCode) > 1 ORDER BY date ASC";
    $sql .= $this->limit;

    $result = mysqli_query($this->mysql, $sql) or die(mysqli_error($this->mysql));

    $rows = mysqli_num_rows($result);
    $progress = $this->getApplication()->getHelperSet()->get('progress');
    $progress->start($this->output, $rows);

    while ($item = mysqli_fetch_object($result)) {
      $tutorial = new Tutorial();

      $tutorial->id = UUID::generate(UUID::UUID_RANDOM, UUID::FMT_STRING);
      $tutorial->publishingDate = (int)$item->unixTime;
      $tutorial->title = iconv('LATIN1', 'UTF-8', rtrim($item->title, '()/123456789 \t\n\r\0\x0B'));

      if (isset($item->userId)) {
        $tutorial->userId = $item->userId;
        $tutorial->username = NULL;
      }
      elseif (!empty($item->contributorName)) {
        $tutorial->userId = NULL;
        $tutorial->username = iconv('LATIN1', 'UTF-8', $item->contributorName);
      }
      else {
        $tutorial->userId = NULL;
        $tutorial->username = NULL;
      }

      $sql = "SELECT id, UNIX_TIMESTAMP(date) AS unixTime, hitNum FROM Item WHERE correlationCode = '".$item->correlationCode."' ORDER BY date ASC";

      $related = mysqli_query($this->mysql, $sql) or die(mysqli_error($this->mysql));

      $i = 0;
      while ($article = mysqli_fetch_object($related)) {
        $tutorial->addPost($article->id, $i);

        // We update the total tutorial views.
        $this->redis->hIncrBy($tutorial->id, 'hits', $article->hitNum);

        $i++;
      }

      // We finally save the tutorial.
      try {
        //$this->couch->saveDoc($article);
        $tutorial->save();
      }
      catch(\Exception $e) {
        $this->monolog->addCritical($e);
        $this->monolog->addCritical(sprintf("Invalid JSON: %d - %s", $item->idItem, $tutorial->title));
      }

      $progress->advance();
    }

    mysqli_free_result($result);

    $progress->finish();
  }


  //! @brief Imports books.
  private function importBooks() {
    $this->output->writeln("Importing books...");

    $sql = "SELECT idItem, I.id AS id, M.id AS userId, contributorName, I.title, body, UNIX_TIMESTAMP(date) AS unixTime, hitNum, locked FROM Item I LEFT OUTER JOIN Member M USING (idMember) WHERE (stereotype = ".self::BOOK.") ORDER BY date DESC";
    $sql .= $this->limit;

    $result = mysqli_query($this->mysql, $sql) or die(mysqli_error($this->mysql));

    $rows = mysqli_num_rows($result);
    $progress = $this->getApplication()->getHelperSet()->get('progress');
    $progress->start($this->output, $rows);

    while ($item = mysqli_fetch_object($result)) {
      $book = new Book();

      $book->id = $item->id;
      $book->publishingDate = (int)$item->unixTime;
      $book->title = iconv('LATIN1', 'UTF-8', $item->title);

      if (!is_null($item->userId)) {
        $book->userId = $item->userId;
        $book->username = NULL;
      }
      elseif (!empty($item->contributorName)) {
        $book->userId = NULL;
        $book->username = iconv('LATIN1', 'UTF-8', $item->contributorName);
      }
      else {
        $book->userId = NULL;
        $book->username = NULL;
      }

      if (preg_match('/\\[isbn\\](.*?)\\[\/isbn\\]/s', $item->body, $matches))
        $book->isbn = iconv('LATIN1', 'UTF-8', $matches[1]);
      if (preg_match('/\\[authors\\](.*?)\\[\/authors\\]/s', $item->body, $matches))
        $book->authors = iconv('LATIN1', 'UTF-8', $matches[1]);
      if (preg_match('/\\[publisher\\](.*?)\\[\/publisher\\]/s', $item->body, $matches))
        $book->publisher = iconv('LATIN1', 'UTF-8', $matches[1]);
      if (preg_match('/\\[language\\](.*?)\\[\/language\\]/s', $item->body, $matches))
        $book->language = iconv('LATIN1', 'UTF-8', $matches[1]);
      if (preg_match('/\\[year\\](.*?)\\[\/year\\]/s', $item->body, $matches))
        $book->year = $matches[1];
      if (preg_match('/\\[pages\\](.*?)\\[\/pages\\]/s', $item->body, $matches))
        $book->pages = $matches[1];
      if (preg_match('/\\[attachments\\](.*?)\\[\/attachments\\]/s', $item->body, $matches) && !empty($matches[1]))
        $book->attachments = iconv('LATIN1', 'UTF-8', $matches[1]);
      if (preg_match('/\\[review\\](.*?)\\[\/review\\]/s', $item->body, $matches))
        $review = iconv('LATIN1', 'UTF-8', $matches[1]);
      if (preg_match('/\\[positive\\](.*?)\\[\/positive\\]/s', $item->body, $matches))
        $positive = iconv('LATIN1', 'UTF-8', $matches[1]);
      if (preg_match('/\\[negative\\](.*?)\\[\/negative\\]/s', $item->body, $matches))
        $negative = iconv('LATIN1', 'UTF-8', $matches[1]);

      if (preg_match('/\\[vendorLink\\](.*?)\\[\/vendorLink\\]/s', $item->body, $matches) && !empty($matches[1]))
        $book->link = iconv('LATIN1', 'UTF-8', $matches[1]);


      // Converts from BBCode to Markdown!
      $converter = new BBCodeConverter($review, $item->id);
      $book->body = $converter->toMarkdown();

      try {
        $book->html = $this->markdown->parse($book->body);
      }
      catch(\Exception $e) {
        $this->monolog->addCritical(sprintf(" %d - %s", $item->idItem, $book->title));
      }

      $purged = Text::purge($book->html);
      $book->excerpt = Text::truncate($purged);

      $converter = new BBCodeConverter($positive, $item->id);
      $book->positive = $converter->toMarkdown();

      $converter = new BBCodeConverter($negative, $item->id);
      $book->negative = $converter->toMarkdown();

      // We finally save the book.
      try {
        //$this->couch->saveDoc($book);
        $book->save();
      }
      catch(\Exception $e) {
        $this->monolog->addCritical(sprintf("Invalid JSON: %d - %s", $item->idItem, $book->title));
      }

      // We update the book views.
      $this->redis->hSet($book->id, 'hits', $item->hitNum);

      $progress->advance();
    }

    mysqli_free_result($result);

    $progress->finish();
  }


  //! @brief Imports tags.
  private function importTags() {
    $this->output->writeln("Importing tags...");

    $sql = "SELECT id FROM Member WHERE idMember = 1";
    $result = mysqli_query($this->mysql, $sql) or die(mysqli_error($this->mysql));
    $userId = mysqli_fetch_array($result)['id'];
    mysqli_free_result($result);

    $sql = "SELECT id, idCategory, name, UNIX_TIMESTAMP(lastUpdate) AS unixTime, passed FROM Category";
    $sql .= $this->limit;

    $result = mysqli_query($this->mysql, $sql) or die(mysqli_error($this->mysql));

    $rows = mysqli_num_rows($result);
    $progress = $this->getApplication()->getHelperSet()->get('progress');
    $progress->start($this->output, $rows);

    while ($item = mysqli_fetch_object($result)) {
      $tag = new Tag();

      $tag->id = $item->id;
      $tag->publishingDate = (int)$item->unixTime;
      $tag->name = iconv('LATIN1', 'UTF-8', strtolower(str_replace(" ", "-", $item->name)));
      $tag->userId = $userId;

      $this->couch->saveDoc($tag);

      $progress->advance();
    }

    mysqli_free_result($result);

    $progress->finish();
  }


  //! @brief Imports classifications.
  private function importClassifications() {
    $this->output->writeln("Importing classifications...");

    $sql = "SELECT I.id AS itemId, C.id AS tagId, I.stereotype AS stereotype, UNIX_TIMESTAMP(I.date) AS unixTime FROM Item I, Category C, ItemsXCategory X WHERE I.idItem = X.idItem AND C.idCategory = X.idCategory AND (I.stereotype = 2 OR I.stereotype = 11)";
    $sql .= $this->limit;

    $result = mysqli_query($this->mysql, $sql) or die(mysqli_error($this->mysql));

    $rows = mysqli_num_rows($result);
    $progress = $this->getApplication()->getHelperSet()->get('progress');
    $progress->start($this->output, $rows);

    while ($item = mysqli_fetch_object($result)) {

      if ($item->stereotype == self::ARTICLE)
        $postType = 'article';
      else
        $postType = 'book';

      $doc = Classification::create($item->itemId, $postType, 'blog', $item->tagId, (int)$item->unixTime);

      $this->couch->saveDoc($doc);

      $progress->advance();
    }

    mysqli_free_result($result);

    $progress->finish();
  }


  //! @brief Imports favourites.
  private function importFavorites() {
    $this->output->writeln("Importing favorites...");

    $sql = "SELECT I.id AS itemId, I.stereotype, M.id AS userId, UNIX_TIMESTAMP(F.date) as timestamp FROM Item I, Member M, Favourite F WHERE I.idItem = F.idItem AND M.idMember = F.idMember AND (I.stereotype = 2 OR I.stereotype = 11) ";
    $sql .= $this->limit;

    $result = mysqli_query($this->mysql, $sql) or die(mysqli_error($this->mysql));

    $rows = mysqli_num_rows($result);
    $progress = $this->getApplication()->getHelperSet()->get('progress');
    $progress->start($this->output, $rows);

    while ($item = mysqli_fetch_object($result)) {
      $timestamp = (int)$item->timestamp;

      if ($item->stereotype == 2)
        $itemType = 'article';
      else
        $itemType = 'book';

      if ($timestamp > 0)
        $doc = Star::create($item->userId, $item->itemId, $itemType, $timestamp);
      else
        $doc = Star::create($item->userId, $item->itemId, $itemType);

      $this->couch->saveDoc($doc);

      $progress->advance();
    }

    mysqli_free_result($result);

    $progress->finish();
  }


  //! @brief Imports subscriptions.
  private function importSubscriptions() {
    $this->output->writeln("Importing subscriptions...");

    $sql = "SELECT I.id AS itemId, M.id AS userId, UNIX_TIMESTAMP(T.creationTime) as timestamp FROM Item I, Member M, Thread T WHERE I.idItem = T.idItem AND M.idMember = T.idMember";
    $sql .= $this->limit;

    $result = mysqli_query($this->mysql, $sql) or die(mysqli_error($this->mysql));

    $rows = mysqli_num_rows($result);
    $progress = $this->getApplication()->getHelperSet()->get('progress');
    $progress->start($this->output, $rows);

    while ($item = mysqli_fetch_object($result)) {
      $timestamp = (int)$item->timestamp;

      if ($timestamp > 0)
        $doc = Subscription::create($item->itemId, $item->userId, $timestamp);
      else
        $doc = Subscription::create($item->itemId, $item->userId);

      $this->couch->saveDoc($doc);

      $progress->advance();
    }

    mysqli_free_result($result);

    $progress->finish();
  }


  //! @brief Imports comments.
  private function importReplies() {
    $this->output->writeln("Importing comments...");

    $sql = "SELECT C.idComment AS id, I.id AS postId, M.id AS userId, UNIX_TIMESTAMP(C.date) AS unixTime, C.body FROM Comment C, Item I, Member M WHERE C.idItem = I.idItem AND C.idMember = M.idMember ORDER BY C.date DESC";
    $sql .= $this->limit;

    $result = mysqli_query($this->mysql, $sql) or die(mysqli_error($this->mysql));

    $rows = mysqli_num_rows($result);
    $progress = $this->getApplication()->getHelperSet()->get('progress');
    $progress->start($this->output, $rows);

    while ($item = mysqli_fetch_object($result)) {
      $comment = new Reply();

      $comment->id = UUID::generate(UUID::UUID_RANDOM, UUID::FMT_STRING);
      $comment->publishingDate = (int)$item->unixTime;
      $comment->postId = $item->postId;
      $comment->userId = $item->userId;

      $comment->body = iconv('LATIN1', 'UTF-8', $item->body);

      // Converts from HTML to BBCode!
      $converter = new HTMLConverter($comment->body, $item->id);
      $comment->body = $converter->toBBCode();

      // Converts from BBCode to Markdown!
      $converter = new BBCodeConverter($comment->body, $item->id);
      $comment->body = $converter->toMarkdown();

      try {
        $comment->html = $this->markdown->parse($comment->body);
      }
      catch(\Exception $e) {
        $this->monolog->addCritical(sprintf(" %d - %s", $item->id, $comment->title));
      }

      // We finally save the comment.
      try {
        //$this->couch->saveDoc($article);
        $comment->save();
      }
      catch(\Exception $e) {
        $this->monolog->addCritical($e);
        $this->monolog->addCritical(sprintf("Invalid JSON: %d", $item->id));
      }

      $progress->advance();
    }

    mysqli_free_result($result);

    $progress->finish();
  }


  //! @brief Imports all entities.
  private function importAll() {
    $this->importUsers();
    $this->importArticles();
    $this->importBooks();
    $this->importTags();
    $this->importClassifications();
    $this->importFavorites();
    $this->importTutorials();
    $this->importSubscriptions();
    $this->importReplies();
  }


  //! @brief Configures the command.
  protected function configure() {
    $this->setName("import");
    $this->setDescription("Imports into CouchDB the data from Programmazione.it v6.4 MySQL database.");
    $this->addArgument("entities",
        InputArgument::IS_ARRAY | InputArgument::REQUIRED,
        "The entities you want import. Use 'all' if you want import all the entities, 'users' if you want just import the
        users or separate multiple entities with a space. The available entities are: users, articles, books, tags,
        classifications, favorites, tutorials, subscriptions.");
    $this->addOption("limit",
        NULL,
        InputOption::VALUE_OPTIONAL,
        "Limit the imported records.");
  }


  //! @brief Executes the command.
  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->mysql = $this->di['mysql'];
    $this->couch = $this->di['couchdb'];
    $this->redis = $this->di['redis'];
    $this->markdown = $this->di['markdown'];

    $this->input = $input;
    $this->output = $output;

    $entities = $input->getArgument('entities');
    $limit = (int)$input->getOption('limit');

    if ($limit > 0)
      $this->limit = " LIMIT ".(string)$limit;
    else
      $this->limit = "";

    // Checks if the argument 'all' is provided.
    $index = array_search("all", $entities);

    if ($index === FALSE) {

      foreach ($entities as $name)
        switch ($name) {
          case 'users':
            $this->importUsers();
            break;

          case 'articles':
            $this->importArticles();
            break;

          case 'books':
            $this->importBooks();
            break;

          case 'tags':
            $this->importTags();
            break;

          case 'classifications':
            $this->importClassifications();
            break;

          case 'favorites':
            $this->importFavorites();
            break;

          case 'tutorials':
            $this->importTutorials();
            break;

          case 'subscriptions':
            $this->importSubscriptions();
            break;

          case 'replies':
            $this->importReplies();
            break;
        }

    }
    else
      $this->importAll();

    $this->couch->ensureFullCommit();
  }

}