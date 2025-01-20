(function ($, Drupal) {
  Drupal.behaviors.aiSearchBlock = {
    attach: function (context, settings) {
      if (context !== document) {
        return;
      }
      let $suffix_text = $('#ai-search-block').find('.suffix_text');
      $suffix_text.hide();

      $('.ai-search-block-form').submit(function(e){
        e.preventDefault();
        let $form = $(e.currentTarget);
        var $output_region = $($form).find('.ai-search-block-result-message');
        $output_region.html(drupalSettings.ai_search_block.loading_text);
        const $input = $form.find('[data-drupal-selector="edit-query"]');
        const $inputText = $input.val();
        const $stream = $form.find('[data-drupal-selector="edit-stream"]').val();
        const $block_id = $form.find('[data-drupal-selector="edit-block-id"]').val();
        try{
          if ($stream) {
            let lastResponseLength = false;
            xhr = new XMLHttpRequest();
            xhr.open("POST", drupalSettings.ai_search_block.submit_url, true);
            xhr.setRequestHeader("Content-Type", "application/json");
            xhr.setRequestHeader("Accept", "application/json");
            xhr.send(JSON.stringify({
              query: $inputText,
              stream: $stream,
              block_id: $block_id,
            }));
            xhr.onprogress = function(e) {
              const newUpdates = xhr.responseText
                .replace('false', 'true')
                .trim()
                .split('||')
                .filter(Boolean);
              const newUpdatesParsed = newUpdates.map((update) => {
                const parsed = JSON.parse(update);
                return parsed.answer_piece || '';
              });
              const joined = newUpdatesParsed.join('');
              $output_region.html(joined);
            }
            xhr.onreadystatechange = function() {
              if (xhr.readyState == 4 && this.status == 200) {
                $suffix_text.html(drupalSettings.ai_search_block.suffix_text);
                $suffix_text.show();
              }
            }
            xhr.send();
          }else{
            var jqxhr = $.post( drupalSettings.ai_search_block.submit_url,
              {
                query: $inputText,
                stream: $stream,
                block_id: $block_id,
              }
              ,function(data) {
                $output_region.html(data.response);
                $suffix_text.html(drupalSettings.ai_search_block.suffix_text);
                $suffix_text.show();
              })
              .done(function() {
                //alert( "second success" );
              })
              .fail(function() {
                //alert( "error" );
              })
              .always(function() {
                //alert( "finished" );
              });
            jqxhr.always(function() {
              //alert( "second finished" );
            });
          }

        }
        catch (e) {

        }
        e.stopImmediatePropagation();
        return false;
      })($suffix_texts);
    }
  };
})(jQuery, Drupal);
