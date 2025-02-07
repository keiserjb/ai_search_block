(function ($, Drupal) {
  Drupal.behaviors.aiSearchBlockLog = {
    attach: function (context, settings) {
      let $suffix_text = $('#ai-search-block-response .suffix_text');
      $('.ai_search_block_log_score').each(function (index) {
        $(this).on("click", function () {
          var $score = $(this).data('aiSearchBlockLogScore');
          var $logId = drupalSettings.ai_search_block.logId;
          var jqxhr = $.post('/ai-search-block-log/score',
            {
              log_id: $logId,
              score: $score,
            }
            , function (data) {
              $suffix_text.html(data.response);
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

        });
      });
    }
  };
})(jQuery, Drupal);
