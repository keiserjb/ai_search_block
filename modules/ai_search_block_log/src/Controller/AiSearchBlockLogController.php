<?php

namespace Drupal\ai_search_block_log\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai_search_block\AiSearchBlockHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * An example controller.
 */
class AiSearchBlockLogController extends ControllerBase {

  /**
   * Returns a renderable array for a test page.
   *
   * Return []
   */
  public function score(Request $request) {
    $logId = null;
    if ($request->get('log_id')) {
      $logId = $request->get('log_id');
      $score = $request->get('score');
    }
    $helper = \Drupal::service('ai_search_block_log.helper');
    $helper->update((int) $logId, ['score' => (int) $score]);

    // Todo make this configurable.
    return new JsonResponse(
      [
        'response' => $this->t('Thank you for your feedback.'),
      ]);
  }

}
