<?php

//! @file LinkedInConsumer.php
//! @brief This file contains the LinkedInConsumer class.
//! @details
//! @author Filippo F. Fadda


namespace PitPress\Security\Consumer;


use PitPress\Model\User;
use PitPress\Exception\InvalidFieldException;


/**
 * @brief LinkedIn consumer implementation.
 * @nosubgrouping
 */
class LinkedInConsumer extends OAuth2Consumer {

  /** @name Field Names */
  //!@{
  const ID = 'id';
  const EMAIL = 'emailAddress';
  const FIRST_NAME = 'firstName';
  const LAST_NAME = 'lastName';
  const BIRTHDAY = 'dateOfBirth';
  const HEADLINE = 'headline';
  const ABOUT = 'firstName';
  const PROFILE_URL = 'publicProfileUrl';
  //!@}


  // LinkedIn, like Facebook, doesn't provide a username, but PitPress needs one. So we guess the username using the
  // user public profile url. In case the username has already been taken, we add a sequence number to the end.
  // todo this is not finished
  private function guessUsername($publicProfileUrl) {
    if (preg_match('%.+/in/(?P<username>.+)%i', $publicProfileUrl, $matches))
      return $matches['username'];
    else
      throw new InvalidFieldException("Le informazioni fornite da LinkedIn sono incomplete.");
  }


  protected function update(User $user, array $userData) {
    $user->setMetadata('username', $this->guessUsername($userData[static::PROFILE_URL]), FALSE, FALSE);
    $user->setMetadata('firstName', @$userData[static::FIRST_NAME], FALSE, FALSE);
    $user->setMetadata('lastName', @$userData[static::LAST_NAME], FALSE, FALSE);
    $user->setMetadata('birthday', @$userData[static::BIRTHDAY], FALSE, FALSE);
    $user->setMetadata('headline', @$userData[static::HEADLINE], FALSE, FALSE);
    $user->setMetadata('about', @$userData[static::ABOUT], FALSE, FALSE);

    $user->addLogin($this->getName(), $userData[static::ID], $userData[static::EMAIL], $userData[static::PROFILE_URL]);
    $user->internetProtocolAddress = $_SERVER['REMOTE_ADDR'];
    $user->save();
  }


  public function join() {
    $userData = $this->fetch('/people/~:(id,email-address,first-name,last-name,public-profile-url,headline,summary,date-of-birth)?format=json');
    $this->validate($userData);
    $this->consume($userData[static::ID], $userData[static::EMAIL], $userData);
  }


  public function getName() {
    return 'linkedin';
  }


  public function getScope() {
    return ['r_fullprofile', 'r_emailaddress', 'r_contactinfo', 'r_network'];
  }


  public function getFriends() {

  }

}