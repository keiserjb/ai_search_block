(function ($, Drupal, drupalSettings, once) {
  'use strict';

  // Utility to dispatch custom status events
  function dispatchStatusEvent(status, extra) {
    var event = new CustomEvent('ai-search-status', {
      detail: Object.assign({ status: status }, extra || {})
    });
    document.dispatchEvent(event);
  }

  Drupal.behaviors.aiSearchBlock = {
    attach: function (context, settings) {
      once('aiSearchForm', '.ai-search-block-form', context).forEach(function (formElem) {
        var $form = $(formElem);

        var $resultsBlock = $('#ai-search-block-response .ai-search-block-output');
        var $suffixText = $('#ai-search-block-response .suffix_text');
        var $dbResults = $('#ai-search-block-db-results');
        if (!$dbResults.length) {
          $dbResults = $('<div id="ai-search-block-db-results"></div>');
          $resultsBlock.after($dbResults);
        } else {
          $dbResults.empty();
        }

        if (!$resultsBlock.length) {
          console.warn('AI Search: Could not find a results block relative to the form.');
          return;
        }
        if ($suffixText.length) {
          $suffixText.hide();
        }

        // One-time CSS: hide exposed form + remove visible focus ring on container.
        if (!$('style#ai-hide-exposed').length) {
          $('<style id="ai-hide-exposed">\
#ai-search-block-db-results .views-exposed-form{display:none!important}\
#ai-search-block-db-results:focus{outline:0!important; box-shadow:none!important}\
</style>').appendTo('head');
        }

        var loadingMsg = drupalSettings.ai_search_block && drupalSettings.ai_search_block.loading_text
          ? drupalSettings.ai_search_block.loading_text
          : 'Loading...';
        var $loader = $('<p class="loading_text"><span class="loader"></span>' + loadingMsg + '</p>');

        // ------------------- Helpers -------------------

        // Offset for admin toolbar + optional fixed site header.
        function getScrollOffset() {
          var extra = 0;
          var $toolbar = $('#toolbar-bar:visible'); // Drupal admin toolbar
          if ($toolbar.length) extra += $toolbar.outerHeight();

          // Adjust for your theme’s fixed header, if any:
          var $fixedHeader = $('.site-header.is-fixed:visible, header.fixed:visible');
          if ($fixedHeader.length) extra += $fixedHeader.outerHeight();

          var cfg = drupalSettings.ai_search_block && drupalSettings.ai_search_block.scroll_offset;
          if (typeof cfg === 'number') extra += cfg;

          return extra;
        }

        // Smooth-scroll to top of results and keep a11y focus (no visible outline due to CSS above).
        function scrollToResults($container) {
          if (!$container || !$container.length) return;
          var top = Math.max(0, $container.offset().top - getScrollOffset() - 12);
          $('html, body').stop(true).animate({ scrollTop: top }, 200);
          $container.attr('tabindex', '-1').focus();
        }

        // Keep Views’ exposed form hidden & out of tab order, even after behaviors attach.
        function hideExposedForm($container) {
          var $exposed = $container.find('form.views-exposed-form');
          if (!$exposed.length) return;
          $exposed.attr('aria-hidden', 'true')
            .addClass('ai-exposed-hidden')
            .hide();
          $exposed.find('input,select,textarea,button,a').attr('tabindex', '-1');
        }

        // Pager helpers (client-side UI correction so active state + First/Prev match requested page)
        function buildPagerHref(query, pageNum) {
          var params = new URLSearchParams();
          if (query) params.set('search_api_fulltext', query);
          params.set('page', pageNum); // zero-based like Views expects
          return '?' + params.toString();
        }

        function fixPager($container, currentPage, query) {
          var $ul = $container.find('ul.pagination, ul.js-pager__items').first();
          if (!$ul.length) return;

          // 1) Reset existing active state and convert spans back to links
          $ul.find('.page-item').removeClass('active').each(function () {
            var $li = $(this);
            var $span = $li.find('> span.page-link');
            if ($span.length) {
              var label = $span.text().trim();
              $span.replaceWith($('<a class="page-link" href="#"></a>').text(label));
            }
          });

          // 2) Activate the requested page and swap to <span>
          var targetLabel = (currentPage + 1).toString();
          var $liForPage = $ul.find('.page-item').filter(function () {
            var $a = $(this).find('> a.page-link');
            var $s = $(this).find('> span.page-link');
            var text = ($a[0] ? $a.text() : $s.text()).trim();
            return /^\d+$/.test(text) && text === targetLabel;
          }).first();

          if ($liForPage.length) {
            $liForPage.addClass('active');
            var $a = $liForPage.find('> a.page-link');
            if ($a.length) $a.replaceWith($('<span class="page-link"></span>').text(targetLabel));
          }

          // 3) Normalize numeric link hrefs (so our delegation keeps working)
          $ul.find('.page-item > a.page-link').each(function () {
            var $a = $(this);
            var t = $a.text().trim();
            if (/^\d+$/.test(t)) {
              var n = parseInt(t, 10) - 1; // labels are 1-based
              $a.attr('href', buildPagerHref(query, n));
            }
          });

          // 4) Ensure First/Prev exist when not on page 0 (synthetic if absent)
          $ul.find('li.page-item[data-synth]').remove();
          if (currentPage > 0) {
            var prevHref  = buildPagerHref(query, currentPage - 1);
            var firstHref = buildPagerHref(query, 0);

            var $prevLi = $('<li class="page-item" data-synth="prev">' +
              '<a class="page-link" rel="prev" title="Go to previous page" href="'+prevHref+'">' +
              '<span aria-hidden="true">‹‹</span><span class="visually-hidden">Previous page</span></a></li>');

            var $firstLi = $('<li class="page-item" data-synth="first">' +
              '<a class="page-link" title="Go to first page" href="'+firstHref+'">' +
              '<span aria-hidden="true">« First</span><span class="visually-hidden">First page</span></a></li>');

            var $firstNumeric = $ul.find('.page-item').filter(function () {
              var txt = $(this).find('> a.page-link, > span.page-link').first().text().trim();
              return /^\d+$/.test(txt);
            }).first();

            if ($firstNumeric.length) {
              $firstNumeric.before($prevLi).before($firstLi);
            } else {
              $ul.prepend($firstLi).prepend($prevLi);
            }
          }
        }

        function getPageFromHref(href) {
          try {
            var u = new URL(href, window.location.href);
            var p = u.searchParams.get('page');
            return p ? parseInt(p, 10) : 0; // zero-based
          } catch (e) {
            return 0;
          }
        }

        // ---------------- End Helpers ------------------

        $form.on('submit', function (event) {
          event.preventDefault();

          const submitButton = $form.find('[data-drupal-selector="edit-submit"]');
          submitButton.prop('disabled', true);

          $resultsBlock.html($loader);
          $dbResults.empty();

<<<<<<< HEAD
          // Dispatch loading status
          dispatchStatusEvent('loading', { form: $form[0] });

          // Retrieve form values.
=======
>>>>>>> f7bf210 (add view to response)
          var queryVal = $form.find('[data-drupal-selector="edit-query"]').val() || '';
          var streamVal = $form.find('[data-drupal-selector="edit-stream"]').val() === 'true';
          var blockIdVal = $form.find('[data-drupal-selector="edit-block-id"]').val() || '';

          function fetchDbResults(page) {
        // Keep track of the current in-flight DB request so we can cancel it if needed.
        var currentDbRequest = null;

        function fetchDbResults(page) {
          if (typeof page === 'undefined') page = 0;

          // Cancel any in-flight request
          if (currentDbRequest && currentDbRequest.readyState !== 4) {
            currentDbRequest.abort();
          }

          $dbResults.html('<p class="loading_text">Loading database results...</p>');

          currentDbRequest = $.ajax({
            url: (drupalSettings.ai_search_block && drupalSettings.ai_search_block.db_results_url) || '/ai-search-block/db-results',
            type: 'POST',
            data: {
              query: queryVal,
              block_id: blockIdVal,
              page: page
            },
            success: function (data) {
              if (data && data.html) {
                $dbResults.html(data.html);

                // Kill Views' own AJAX class if it slipped in.
                $dbResults.find('a.use-ajax').removeClass('use-ajax');

                // Ensure responsive grid CSS is present (one-time)
                if (!$('link[href*="views-responsive-grid.css"]').length) {
                  var link = document.createElement('link');
                  link.rel = 'stylesheet';
                  link.href = drupalSettings.path.baseUrl + 'core/modules/views/css/views-responsive-grid.css';
                  document.head.appendChild(link);
                }

                // Reattach behaviors for markup (tooltips, etc.)
                Drupal.attachBehaviors($dbResults[0]);

                // Keep pager UI correct for the page we just requested
                fixPager($dbResults, page || 0, queryVal);

                // Hide the exposed filter reliably
                hideExposedForm($dbResults);

                // Scroll back to the top of results
                scrollToResults($dbResults);

                // Delegate pager clicks to our AJAX loader (avoid stacking)
                $dbResults
                  .off('click.aiPager')
                  .on('click.aiPager', 'a', function (e) {
                    var href = this.getAttribute('href') || '';
                    if (
                      href.indexOf('page=') !== -1 ||
                      $(this).closest('.pager, .pagination, .views-pager').length
                    ) {
                      e.preventDefault();
                      e.stopPropagation();
                      e.stopImmediatePropagation();
                      var nextPage = getPageFromHref(href);
                      fetchDbResults(nextPage);
                      // also scroll on pager click
                      scrollToResults($dbResults);
                      return false;
                    }
                  });
              } else {
                $dbResults.html('<p>No database results found.</p>');
              }
            },
            error: function () {
              $dbResults.html('<p>Error loading database results.</p>');
            }
          });
        }

          if (streamVal) {
            try {
              var xhr = new XMLHttpRequest();
              xhr.open('POST', drupalSettings.ai_search_block.submit_url, true);
              xhr.setRequestHeader('Content-Type', 'application/json');
              xhr.setRequestHeader('Accept', 'application/json');

              var lastResponseLength = 0;
              var joined = '';

              xhr.onprogress = function () {
                var responseText = xhr.responseText || '';
                var newData = responseText.substring(lastResponseLength);
                lastResponseLength = responseText.length;
                var chunks = newData.trim().split('|§|').filter(Boolean);

                chunks.forEach(function (chunk) {
                  try {
                    var parsed = JSON.parse(chunk);
                    if (parsed.log_id) {
                      drupalSettings.ai_search_block.logId = parsed.log_id;
                    }
                    joined += parsed.answer_piece || '';
                  } catch (e) {
                    console.error('Error parsing chunk:', e, chunk);
                  }
                });
                $resultsBlock.html(joined).append($loader);

                // Dispatch streaming progress status
                dispatchStatusEvent('streaming', { progress: joined.length, form: $form[0] });
              };

              xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                  $loader.remove();
                  submitButton.prop('disabled', false);
                  if (xhr.status === 200) {
                    if ($suffixText.length) {
                      $suffixText.html(drupalSettings.ai_search_block.suffix_text);
                      Drupal.attachBehaviors($suffixText[0]);
                      $suffixText.show();
                    }
<<<<<<< HEAD
                    // Dispatch done status
                    dispatchStatusEvent('done', { response: joined, form: $form[0] });
                  } else if (xhr.status === 500) {
                    $resultsBlock.html('An error happened.');
                    console.error('Error response:', xhr.responseText);
                    submitButton.prop('disabled', false);
                    // Dispatch error status
                    dispatchStatusEvent('error', { error: xhr.responseText, form: $form[0] });
                    try {
                      var parsedError = JSON.parse(xhr.responseText);
                      if (parsedError.response && parsedError.response.answer_piece) {
                        $resultsBlock.html(parsedError.response.answer_piece);
                      }
                      Drupal.attachBehaviors($resultsBlock[0]);
                    } catch (e) {
                      console.error('Error parsing 500 response:', e);
                    }
=======
                    fetchDbResults();
                  } else if (xhr.status === 500) {
                    $resultsBlock.html('An error happened.');
                    $dbResults.empty();
>>>>>>> f7bf210 (add view to response)
                  }
                }
              };

<<<<<<< HEAD
              // Send the streaming request.
              xhr.send(
                JSON.stringify({
                  query: queryVal,
                  stream: streamVal,
                  block_id: blockIdVal
                })
              );
            } catch (e) {
              console.error('XHR error:', e);
              dispatchStatusEvent('error', { error: e, form: $form[0] });
=======
              xhr.send(JSON.stringify({
                query: queryVal,
                stream: streamVal,
                block_id: blockIdVal
              }));
            } catch (e) {
              console.error('XHR error:', e);
              $dbResults.empty();
>>>>>>> f7bf210 (add view to response)
            }
          } else {
            $.post(
              drupalSettings.ai_search_block.submit_url,
              {
                query: queryVal,
                stream: streamVal,
                block_id: blockIdVal
              },
              function (data) {
                if (data && data.response) {
                  $resultsBlock.html(data.response);
                }
<<<<<<< HEAD
                // Set log Id if available.
=======
>>>>>>> f7bf210 (add view to response)
                if (data && data.log_id) {
                  drupalSettings.ai_search_block.logId = data.log_id;
                }
                if ($suffixText.length) {
                  $suffixText.html(drupalSettings.ai_search_block.suffix_text).show();
                  Drupal.attachBehaviors($suffixText[0]);
                }
                submitButton.prop('disabled', false);
<<<<<<< HEAD
                dispatchStatusEvent('done', { response: data, form: $form[0] });
              }
            ).fail(function (jqXHR) {
              $resultsBlock.html('An error happened.');
              console.error('Error on non-streaming request');
              dispatchStatusEvent('error', { error: jqXHR.responseText, form: $form[0] });
            }).always(function() {
=======
                fetchDbResults();
              }
            ).fail(function () {
              $resultsBlock.html('An error happened.');
              $dbResults.empty();
            }).always(function () {
>>>>>>> f7bf210 (add view to response)
              submitButton.prop('disabled', false);
            });
          }

          return false;
        });
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
<<<<<<< HEAD

=======
>>>>>>> f7bf210 (add view to response)
