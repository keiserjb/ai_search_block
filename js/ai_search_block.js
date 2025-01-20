(function ($, Drupal) {
  Drupal.behaviors.aiSearchBlock = {
    attach: function (context, settings) {
      if (context !== document) {
        return;
      }
      var $suffix_texts = $('#ai-search-block').find('.suffix_text');
      $suffix_texts.hide();

      $('.ai-search-block-form').submit(function(e){
        e.preventDefault();
        const $form = $(e.currentTarget);
        var $output_region = $($form).parent('#ai-search-block').find('.ai-search-block-output');
        $output_region.html(drupalSettings.ai_search_block.loading_text);
        var $suffix_text = $($form).parent('#ai-search-block').find('.suffix_text');
        $suffix_text.hide();
        const $input = $form.find('[data-drupal-selector="edit-query"]');
        const $inputText = $input.val();
        const $stream = $form.find('[data-drupal-selector="edit-stream"]').val();
        console.log($stream);
        const $block_id = $form.find('[data-drupal-selector="edit-block-id"]').val();
        console.log($block_id);
        console.log('input fetched' , $input.val());

        try{
          var jqxhr = $.post( drupalSettings.ai_search_block.submit_url,
            {
              query: $inputText,
              stream: $stream,
              block_id: $block_id,
            }
            ,function(data) {
              console.log(data.response);
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
        catch (e) {

        }
      });
    }
  };
})(jQuery, Drupal);
