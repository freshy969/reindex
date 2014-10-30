{% extends "templates/base.volt" %}

{% block content %}
<div id="content">
  <div id="page-title">{{ post.title }}</div>
  <hr class="fade-long">

  <div class="column-left expanded">
    <!-- <div class="alert alert-info">Le tue modifiche saranno poste in coda sino a che il processo di revisione paritaria (peer review) avrà luogo. Ogni modifica, purché costruttiva, è benvenuta. Grazie.</div> -->
    {{ flash.output() }}

    <form action="//{{ domainName }}/nuovo/" id="editform" name="editform" method="post" role="form">

      <select class="half-gutter" name="version" id="select-version">
        {% for revision in revisions %}
          <option value="{{ revision.id }}">Rev. - {{ revision.editor }} - {{ revision.whenHasBeenModified }} - {% if revision.editSummary is empty %} Prima versione {% else %} {{ revision.editSummary }} {% endif  %}</option>
        {% endfor %}
      </select>
      <script>
        $('#select-version').selectize({
          sortField: {
            field: 'text',
            direction: 'asc'
          },
          dropdownParent: 'body'
        });
      </script>

      <ul class="list vertical gutter-minus">
        <li>
          {{ text_field("title", "placeholder": "Titolo") }}
          <label>{{ validation.first("title") }}</label>
        </li>
      </ul>

      <ul class="list tabs">
        <li><span><b>Corpo dell'articolo</b></span></li>
        <li class="pull-right"><a href="#preview" data-toggle="tab">ANTEPRIMA</a></li>
        <li class="active pull-right"><a href="#markdown" data-toggle="tab">MARKDOWN</a></li>
      </ul>
      <div class="notebook gutter">
        <div class="notebook-page active" id="markdown">
          <ul class="list toolbar">
            <li class="toolgroup break">
              <a href="#" title="Grassetto"><i class="icon-bold"></i></a>
              <a href="#" title="Corsivo"><i class="icon-italic"></i></a>
            </li>
            <li class="toolgroup break">
              <a href="#" title="Aggiungi un link"><i class="icon-link"></i></a>
              <a href="#" title="Aggiungi un'immagine"><i class="icon-picture"></i></a>
            </li>
            <li class="toolgroup break">
              <a href="#" title="Quota una parte di testo"><i class="icon-angle-right"></i></a>
              <a href="#" title="Aggiungi un blocco di codice"><i class="icon-code"></i></a>
            </li>
            <li class="toolgroup">
              <a href="#" title="Aggiungi ai preferiti"><i class="icon-ellipsis-horizontal"></i></a>
              <a href="#" title="Lista puntata"><i class="icon-list-ul"></i></a>
              <a href="#" title="Lista numerata"><i class="icon-list-ol"></i></a>
            </li>
          </ul>
          {{ text_area("body") }}
          <label>{{ validation.first("body") }}</label>
          <script type="text/javascript">
            var editor = CodeMirror.fromTextArea(document.getElementById("body"), {
              mode: 'gfm',
              lineNumbers: true,
              lineWrapping: true,
              theme: "default",
              viewportMargin: Infinity
            });

            var charWidth = editor.defaultCharWidth(), basePadding = 4;
            editor.on("renderLine", function(cm, line, elt) {
              var off = CodeMirror.countColumn(line.text, null, cm.getOption("tabSize")) * charWidth;
              elt.style.textIndent = "-" + off + "px";
              elt.style.paddingLeft = (basePadding + off) + "px";
            });

            editor.refresh();
          </script>
        </div>
        <div class="notebook-page" id="preview">
        </div>
      </div>

      <select class="half-gutter" id="tags" name="tags[]" placeholder="Seleziona alcuni tags..."></select>
      <script>
        $('#tags').selectize({
          plugins: ['remove_button'],
          persist: false,
          create: true,
          //theme: 'links',
          maxItems: null,
          valueField: 'id',
          searchField: 'title',
          options: [],
          render: {
            option: function(data, escape) {
              return '<div class="option">' +
              '<span class="title">' + escape(data.title) + '</span>' +
              '</div>';
            },
            item: function(data, escape) {
              return '<div class="item"><a class="tag" href="' + escape(data.url) + '">' + escape(data.title) + '</a></div>';
            }
          },
          create: function(input) {
            return {
              id: 0,
              title: input,
              url: '#'
            };
          },
          load: function(query, callback) {
            if (!query.length) return callback();
            $.ajax({
              url: 'http://ajax.programmazione.me/tags/',
              type: 'GET',
              dataType: 'json',
              data: {
                //q: query,
                //page_limit: 10,
                //apikey: '3qqmdwbuswut94jv4eua3j85'
              },
              error: function() {
                callback();
              },
              success: function(res) {
                callback(res);
              }
            });
          },
          onInitialize: function() {
            {% set tags = post.getTags() %}
            {% for tag in tags %}
            this.addOption({ id: {{ loop.index }}, title: '{{ tag['value'] }}', url: '//{{ domainName }}/{{ tag['value'] }}/' });
            this.addItem({{ loop.index }});
            {% endfor %}
          }
        });
      </script>

      <ul class="list vertical gutter-minus">
        <li>
          {{ text_field("summary", "placeholder": "Breve descrizione delle modifiche apportate") }}
          <label>{{ validation.first("summary") }}</label>
        </li>
      </ul>

      <ul class="list btn-list gutter">
        <li class="pull-right"><a href="//{{ serverName~post.getHref() }}" class="btn">ANNULLA</a></li>
        <li class="pull-right"><button type="submit" name="signin" class="btn red">SALVA LE MODIFICHE</button></li>
      </ul>

    </form>

  </div> <!-- column-left -->

  <aside class="column-right compressed">
    {% include "partials/notes/formatting-rules.volt" %}
    {% include "partials/notes/tags-usage.volt" %}
  </aside> <!-- column-right -->

</div>
{% endblock %}