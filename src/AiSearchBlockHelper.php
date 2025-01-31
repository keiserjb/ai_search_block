<?php

declare(strict_types=1);

namespace Drupal\ai_search_block;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Helper service to do RA stuff.
 */
class AiSearchBlockHelper implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The configuration parameters passed in.
   *
   * @var array
   */
  private $configuration;

  public function __construct(
    protected PrivateTempStoreFactory $tmpStore,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
    protected HtmlConverter $converter,
    protected AiProviderPluginManager $aiProviderManager,
    protected RequestStack $requestStack,
    protected LanguageManagerInterface $languageManager,
    protected AccountProxyInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    $this->converter->getConfig()->setOption('strip_tags', TRUE);
    $this->converter->getConfig()->setOption('strip_placeholder_links', TRUE);
    $this->converter->getEnvironment()->addConverter(new TableConverter());
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      new HtmlConverter(),
      $container->get('ai.provider'),
      $container->get('request_stack'),
      $container->get('language_manager'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('module_handler'),
    );
  }

  /**
   * Set the config for this Search.
   *
   * @param array $config
   * The array wth configuration.
   *
   * @return void
   */
  public function setConfig($config) {
    $this->configuration = $config;
  }

  /**
   * Take rag action.
   *
   * @param string $query
   *   The question from the user.
   *
   * @return \Drupal\Component\Serialization\JsonResponse|string|\Symfony\Component\HttpFoundation\StreamedResponse
   *
   * @throws \Exception
   */
  public function searchRagAction($query) {
    if (!empty($this->configuration['database'])) {
      $rag_database = $this->configuration;
    }
    if (!isset($rag_database)) {
      return $this->giveMeAnError('[ERROR] No RAG database found.');
    }
    $results = $this->getRagResults($rag_database, $query);
    $min_results = $this->configuration['min_results'];
    if ($results->getResultCount() < $min_results) {
      return $this->giveMeAnError($this->configuration['no_results_message']);
    }
    return $this->renderRagResponseAsString($results, $query, $rag_database);
  }

  /**
   * Returns the errors.
   *
   * @param string $msg
   *   The message for the error.
   *
   * @return \Drupal\Component\Serialization\JsonResponse
   *   The Json response.
   */
  public function giveMeAnError($msg) {
    $parts = str_split($msg, 4);
    return $this->streamBackResponse($parts, 'string');
  }

  /**
   * Full entity check with a LLM checking the rendered entity.
   *
   * @param \Drupal\search_api\Item\ItemInterface[] $result_items
   *   The result to check.
   * @param string $query_string
   *   The query to search for.
   * @param array $rag_database
   *   The RAG database array data.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   The response.
   */
  protected function fullEntityCheck(array $result_items, string $query_string, array $rag_database) {
    $entity_list = [];
    $rendered_entities = [];
    foreach ($result_items as $result) {
      $entity_string = $result->getExtraData('drupal_entity_id');
      // Load the entity from search api key.
      // @todo probably exists a function for this.
      [, $entity_parts, $lang] = explode(':', $entity_string);
      [$entity_type, $entity_id] = explode('/', $entity_parts);
      /** @var \Drupal\Core\Entity\ContentEntityBase */
      $entity = $this->entityTypeManager->getStorage($entity_type)
        ->load($entity_id);
      $entity_list[$entity_id] = [
        'lang' => $lang,
        'entity' => $entity,
        'entity_type' => $entity_type,
      ];
    }

    // $entities are filtered now
    foreach ($entity_list as $entity_id => $entity_array) {
      $lang = $entity_array['lang'];
      $entity = $entity_array['entity'];
      $entity_type = $entity_array['entity_type'];
      // Get translated if possible.
      if (
        $entity instanceof TranslatableInterface
        && $entity->language()->getId() !== $lang
        && $entity->hasTranslation($lang)
      ) {
        $entity = $entity->getTranslation($lang);
      }
      // Render the entity in selected view mode.
      $view_mode = $this->configuration['aggregated_llm'] ?? 'full';
      $pre_render_entity = $this->entityTypeManager->getViewBuilder($entity_type)
        ->view($entity, $view_mode);
      $rendered = $this->renderer->render($pre_render_entity);
      $rendered = $this->cleanupHtml($rendered, $entity);
      $markdown = $this->converter->convert((string) $rendered);
      $markdown = $this->cleanupMarkdown($markdown, $entity);
      $rendered_entities[] = $markdown;
    }

    $message = str_replace([
      '[question]',
      '[entity]',
    ], [
      $query_string,
      implode("\n------------\n", $rendered_entities),
    ], nl2br($this->configuration['aggregated_llm']));

    foreach ($this->getPrePromptDrupalContext() as $key => $replace) {
      $message = str_replace('[' . $key . ']', is_null($replace) ? '' : $replace, $message);
    }

    $tomorrow = strtotime('+ 1 day');
    $yesterday = strtotime('- 1 day');
    $date_today = date("D M j G:i:s T Y");
    $date_tomorrow = date("D M j G:i:s T Y", $tomorrow);
    $date_yesterday = date("D M j G:i:s T Y", $yesterday);
    $time_now = date("H:i:s");

    $message = str_replace('[time_now]', $time_now, $message);
    $message = str_replace('[date_today]', $date_today, $message);
    $message = str_replace('[date_tomorrow]', $date_tomorrow, $message);
    $message = str_replace('[date_yesterday]', $date_yesterday, $message);

    // Now we have the entity, we can check it with the LLM.
    $ai_provider_model = $this->configuration['llm_model'];

    if ($ai_provider_model === '') {
      $default_provider = $this->aiProviderManager->getDefaultProviderForOperationType('chat');
      $ai_provider_model = $default_provider['provider_id'] . '__' . $default_provider['model_id'];
      $ai_model_to_use = $default_provider['model_id'];
    }
    else {
      $parts = explode('__', $ai_provider_model);
      $ai_model_to_use = $parts[1];
    }
    $provider = $this->aiProviderManager->loadProviderFromSimpleOption($ai_provider_model);
    $config = [];
    foreach ($this->configuration as $key => $val) {
      $config[$key] = $val;
    }
    $this->moduleHandler->alter('ai_search_block_prompt', $message);
    $input = new ChatInput([
      new ChatMessage('user', $message),
    ]);

    if ($this->configuration['stream']) {
      $provider->streamedOutput();
      $output = $provider->chat($input, $ai_model_to_use, ['ai_search_block']);
      $response = $output->getNormalized();
      if (is_object($response) && $response instanceof StreamedChatMessageIteratorInterface) {
        return $this->streamBackResponse($response);
      }
      else {
        $output = $provider->chat($input, $ai_model_to_use, ['ai_search_block']);
        $response = $output->getNormalized()->getText() . "\n";
        return $response;
      }
    }
    else {
      $output = $provider->chat($input, $ai_model_to_use, ['ai_search_block']);
      $response = $output->getNormalized()->getText() . "\n";
      return $response;
    }
  }

  /**
   * Clean up the HTML of the rendered entity.
   *
   * @param $html
   *   The HTML.
   * @param $entity
   *   The entity (context))
   *
   * @return mixed
   */
  private function cleanupHtml($html, $entity) {
    $this->moduleHandler->alter('ai_search_block_entity_html', $html, $entity);
    return $html;
  }

  /**
   * Clean up the markdown of the entity.
   *
   * @param $markdown
   *   The markdown that will end up in the prompt.
   * @param $entity
   *   The context entity.
   *
   * @return string
   */
  private function cleanupMarkdown($markdown, $entity) {
    // First cleanup multiple empty lines.
    $lines = explode(PHP_EOL, $markdown);
    $newlines = [];
    $prev = NULL;
    foreach ($lines as $line) {
      $newline = trim($line, '\t');
      if ($prev == $newline && $newline == '') {
        continue; // Implicit cleanup of duplicate empty lines.
      }
      $newlines[] = $newline;
      $prev = $newline;
    }
    $markdown = implode(PHP_EOL, $newlines);
    $this->moduleHandler->alter('ai_search_block_entity_markdown', $markdown, $entity);
    return $markdown;
  }

  /**
   * Get preprompt Drupal context.
   *
   * @return string[]
   *   This is the Drupal context that you can add to the pre prompt.
   */
  public function getPrePromptDrupalContext() {
    $context = [];
    $current_request = $this->requestStack->getCurrentRequest();
    $context['is_logged_in'] = $this->currentUser->isAuthenticated() ? 'is logged in' : 'is not logged in';
    $context['user_roles'] = implode(', ', $this->currentUser->getRoles());
    $context['user_id'] = $this->currentUser->id();
    $context['user_name'] = $this->currentUser->getDisplayName();
    $context['user_language'] = $this->currentUser->getPreferredLangcode();
    $context['user_timezone'] = $this->currentUser->getTimeZone();
    $context['page_path'] = $current_request->getRequestUri();
    $context['page_language'] = $this->languageManager->getCurrentLanguage()
      ->getId();
    $context['site_name'] = $this->configFactory->get('system.site')
      ->get('name');
    return $context;
  }

  /**
   * Process RAG.
   *
   * @param array $rag_database
   *   The RAG database array data.
   * @param \Drupal\ai_search_block\string $query_string
   *   The query to search for (optional).
   *
   * @return \Drupal\search_api\Query\ResultSetInterface
   *   The RAG response.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getRagResults(array $rag_database, string $query_string) {
    /** @var \Drupal\search_api\Entity\Index */
    $rag_storage = $this->entityTypeManager->getStorage('search_api_index');
    $index = $rag_storage->load($rag_database['database']);
    if (!$index) {
      throw new \Exception('RAG database not found.');
    }

    try {
      $query = $index->query([
        'limit' => $this->configuration['max_results'],
      ]);
      $query->setOption('search_api_bypass_access', ($this->configuration['access_check'] == 'false'));
      $query->setOption('search_api_ai_get_chunks_result', 'rendered');
      $queries = $query_string;
      $query->keys($queries);
      $results = $query->execute();
    }
    catch (\Exception $e) {
      throw new \Exception('Failed to search: ' . $e->getMessage());
    }
    return $results;
  }

  /**
   * Render the RAG response as string.
   *
   * @param \Drupal\search_api\Query\ResultSet $results
   *   The RAG results.
   * @param string $query
   *   The query to search for (optional).
   * @param array $rag_database
   *   The RAG database array data.
   *
   * @return string
   *   The RAG response.
   */
  protected function renderRagResponseAsString($results, string $query, array $rag_database) {
    $result_items = [];
    foreach ($results->getResultItems() as $result) {
      if ((float) $this->configuration['score_threshold'] > $result->getScore()) {
        continue;
      }
      $result_items[] = $result;
    }
    if (!empty($result_items)) {
      return $this->fullEntityCheck($result_items, $query, $rag_database);
    }
    $parts = str_split($this->configuration['no_results_message'], 4);
    return $this->streamBackResponse($parts, 'string');
  }

  /**
   * Streams back the response so it comes to the frontend nice and fluid.
   *
   * @param array|StreamedChatMessageIteratorInterface $parts
   *   Iterable list of parts.
   * @param string $type
   *   String for simple strings or Streamed responses from AI or other systems.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   The response stream.
   */
  private function streamBackResponse($parts, $type = 'Message') {
    return new StreamedResponse(function () use ($type, $parts) {
      foreach ($parts as $part) {
        $item = [];
        $item['in_html'] = FALSE;
        if ($type == 'string') {
          $item['answer_piece'] = $part;
        }
        else {
          $item['answer_piece'] = $part->getText();
        }
        $out = Json::encode($item);
        unset($item);
        echo $out . '|§|';
        ob_flush();
        flush();
        if ($type == 'string') {
          usleep(50000);
        }
      }
    }, 200, [
      'Cache-Control' => 'no-cache, must-revalidate',
      'Content-Type' => 'text/event-stream',
      'X-Accel-Buffering' => 'no',
    ]);
  }

}
