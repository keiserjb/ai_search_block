(function ($, Drupal, once) {
  Drupal.behaviors.aiSearchBlock = {
    attach: function (context, settings) {
      let $suffix_text = $('#ai-search-block-response .suffix_text');
      $suffix_text.hide();
      let $output_region = $('#ai-search-block-response .ai-search-block-output');

      $('.ai-search-block-form').removeAttr('onsubmit').submit(function (e) {
        e.preventDefault();
        let $form = $(e.currentTarget);
        $output_region.html('<p class="loading_text"><span class="loader"></span>' + drupalSettings.ai_search_block.loading_text + '</p>');
        const $input = $form.find('[data-drupal-selector="edit-query"]');
        const $inputText = $input.val();
        const $stream = $form.find('[data-drupal-selector="edit-stream"]').val() === 'true';
        const $block_id = $form.find('[data-drupal-selector="edit-block-id"]').val();
        try {
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
            xhr.onprogress = function (e) {
              const newUpdates = xhr.responseText
                .replace('false', 'true')
                .trim()
                .split('|§|')
                .filter(Boolean);
              const newUpdatesParsed = newUpdates.map((update) => {
                const parsed = JSON.parse(update);
                drupalSettings.ai_search_block.logId = parsed.log_id;
                return parsed.answer_piece || '';
              });
              const joined = newUpdatesParsed.join('');
              $output_region.html(joined);
            }
            xhr.onreadystatechange = function () {
              if (xhr.readyState == 4 && this.status == 200) {
                $suffix_text.html(drupalSettings.ai_search_block.suffix_text);
                Drupal.attachBehaviors($suffix_text[0]);
                $suffix_text.show();
                drupalSettings.ai_search_block.logId = data.log_id;
              }
              if (xhr.readyState == 4 && this.status == 500) {
                $output_region.html('An error happened.');
                console.log(xhr.responseText);
                const parsed = JSON.parse(xhr.responseText);
                $output_region.html(parsed.response.answer_piece);
                Drupal.attachBehaviors($output_region[0]);
              }
            }
            xhr.send();
          } else {
            var jqxhr = $.post(drupalSettings.ai_search_block.submit_url,
              {
                query: $inputText,
                stream: $stream,
                block_id: $block_id,
              }
              , function (data) {
                $output_region.html(data.response);
                drupalSettings.ai_search_block.logId = data.log_id;
                $suffix_text.html(drupalSettings.ai_search_block.suffix_text);
                Drupal.attachBehaviors($suffix_text[0]);
                $suffix_text.show();
              })
              .done(function () {
                //alert( "second success" );
              })
              .fail(function () {
                //alert( "error" );
              })
              .always(function () {
                //alert( "finished" );
              });
            jqxhr.always(function () {
              //alert( "second finished" );
            });
          }

        } catch (e) {

        }
        e.stopImmediatePropagation();
        return false;
      });
    }
  };
})(jQuery, Drupal, once);
