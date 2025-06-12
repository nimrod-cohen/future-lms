<div class="ui padded height grid stackable">
  <div class="ui form row ui-popover">
    <div class="four wide column">
      <div class="field">
        <div class="ui search selection dropdown courses">
          <div class="text"></div>
        </div>
      </div>
    </div>
    <div class="four wide column">
      <div class="field">
        <div class="ui search selection dropdown classes">
          <div class="text"></div>
        </div>
      </div>
    </div>
  </div>
  <div class="row">
    <div class="sixteen wide column">
      <div class="ui fluid input">
        <input type="text" id="txtSubject" placeholder="נושא" style="direction:rtl; text-align:right" />
      </div>
    </div>
  </div>
  <div class="row">
    <div class="sixteen wide column">
      <div id="mailer-content" />
    </div>
  </div>
  </div>
  <div class="row">
    <div class="sixteen wide column">
      <button class="ui primary button send">Send</button>
      <button class="ui basic teal button send-test">Send test to admins</button>
    </div>
  </div>
</div>
<script>
  jQuery('#mailer-content').trumbowyg({
    lang: 'he',
    autogrow: true
});
</script>