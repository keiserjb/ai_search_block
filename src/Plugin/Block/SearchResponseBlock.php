<?php

namespace Drupal\ai_search_block\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides AI response + native Views results.
 *
 * @Block(
 *   id = "ai_search_block_response",
 *   admin_label = @Translation("AI Search Response"),
 *   category = @Translation("AI")
 * )
 */
class SearchResponseBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // Container parameter is required by the interface but not used in this implementation.
    return new static($configuration, $plugin_id, $plugin_definition);

  public function defaultConfiguration(): array {
    return [
      // ... your existing settings ...
      'enable_database_results' => 0,
      'database_results_view' => '',
    ];
  }

  public function build() {
    $build = [];
    // Your existing response wrapper + output + suffix etc.
    $build['response_wrapper'] = [
      '#theme' => 'ai_search_block_response',
      '#output' => ' ',
      '#weight' => 0,
    ];

    // Place the Views block natively so Views AJAX works normally.
    if (!empty($this->configuration['enable_database_results']) && !empty($this->configuration['database_results_view'])) {
      $view_parts = explode(':', $this->configuration['database_results_view']);
      if (count($view_parts) === 2) {
        [$view_id, $display_id] = $view_parts;
        $plugin_id = "views_block:{$view_id}-{$display_id}";

        $plugin_block = \Drupal::service('plugin.manager.block')->createInstance($plugin_id, [
          // Optional per-block settings; leave empty to use display defaults.
        ]);
        $views_build = $plugin_block->build();
        // Wrap with a known ID so JS can find the exposed form reliably.
        $views_build['#attributes']['id'] = 'ai-db-results-block';
        // Ensure Views' own AJAX/cache metadata is preserved.
        $build['db_results'] = $views_build + ['#weight' => 100];
      }
    }

    return $build;
  }

}
