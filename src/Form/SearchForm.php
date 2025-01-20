<?php

namespace Drupal\ai_search_block\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ai_assistant_api\Data\UserMessage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * Provides a ai search form.
 */
class SearchForm extends FormBase {

  use DependencySerializationTrait;

  /**
   * Construct the chat.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param RouteMatchInterface $routeMatcher
   *   The route match.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatcher,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_search_block_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Set the assistant if its not set.
    $context = [];
    foreach ($this->routeMatcher->getParameters()->all() as $key => $data) {
      $context[$key] = $this->routeMatcher->getParameter($key);
    }

//    if (!$this->getRequest()->isXmlHttpRequest()) {
//      // Set the assistant id if its the page load.
//    }

    $response_id = Html::getId($form_state->getBuildInfo()['block_id'] . '-response');
    $search_block_config = $form_state->getBuildInfo()['search_config'];

    $form['stream'] = [
      '#type' => 'hidden',
      '#value' => $search_block_config['stream'],
    ];
    $form['block_id'] = [
      '#type' => 'hidden',
      '#value' => $search_block_config['block_id'],
    ];
    $form['wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'ai-search-form-wrapper', 'class' => ['ai-search-form-wrapper']],
    ];
    $form['wrapper']['query'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ask me a question'),
      '#title_display' => 'invisible',
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $search_block_config['placeholder'],
        'class' => ['chat-form-query'],
        'autocomplete' => 'off',
      ],

      '#rows' => 1,
    ];

    $form['wrapper']['submit'] = [
      '#type' => 'submit',
      '#value' => $search_block_config['submit_text'],
      '#attributes' => [
        'data-ai-ajax' => $response_id,
        'class' => ['search-form-send'],
      ],
    ];

    $form['message'] = [
      '#type' => 'markup',
      '#markup' => '<div class="ai-search-block-result-message"> </div>'
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $search_block_config = $form_state->getBuildInfo()['search_config'];
    $database = $search_block_config['database'];
    //dd($database);
//    $this->aiAssistantRunner->setThreadsKey($form_state->getValue('thread_id'));
//    // Set the user message.
//    $this->aiAssistantRunner->setUserMessage(new UserMessage($form_state->getValue('query')));
//
//    // Send the query to OpenAI.
//    if ($this->getRequest()->isXmlHttpRequest()) {
//      try {
//        $http_response = new StreamedResponse();
//        // Process.
//        $response = $this->aiAssistantRunner->process();
//        // If its a failure, the variable is a string, just output;.
//        if ($response->getNormalized() instanceof ChatMessage) {
//          $output = $response->getNormalized()->getText();
//          // Show structured results if wanted.
//          if ($this->getChatConfig($form_state)['show_structured_results']) {
//            $structured = $this->aiAssistantRunner->getStructuredResults();
//            if ($structured) {
//              $output .= "\n\n<details>\n\n```\n" . Yaml::dump($structured, 10) . "\n```\n\n</details>";
//            }
//          }
//          $http_response = new Response($output);
//          $this->aiAssistantRunner->setAssistantMessage($output);
//          $form_state->setResponse($http_response);
//        }
//        else {
//          $http_response->setCallback(function () use ($response, $form_state) {
//            $full_response = "";
//            $this->aiAssistantRunner->startSession();
//            foreach ($response->getNormalized() as $message) {
//              echo $message->getText();
//              $full_response .= $message->getText();
//              ob_flush();
//              flush();
//            }
//            // Show structured results if wanted.
//            if ($this->getChatConfig($form_state)['show_structured_results']) {
//              $structured = $this->aiAssistantRunner->getStructuredResults();
//              if ($structured) {
//                echo "\n\n<details>\n\n```\n" . Yaml::dump($structured, 10) . "\n```\n\n</details>";
//                $full_response .= "\n\n<details>\n\n```\n" . Yaml::dump($structured, 10) . "\n```\n\n</details>";
//                ob_flush();
//                flush();
//              }
//            }
//            $this->aiAssistantRunner->setAssistantMessage($full_response);
//          });
//          $form_state->setResponse($http_response);
//        }
//      }
//      catch (\Exception $exception) {
//        $http_response = new Response('Error: ' . $exception->getMessage());
//        $form_state->setResponse($http_response);
//      }
//    }
//    else {
//      $response = $this->aiAssistantRunner->process();
//      $form_state->setRebuild();
//      $form_state->set('response', $response->getNormalized()->getText());
//      $this->aiAssistantRunner->setAssistantMessage($response->getNormalized()->getText());
//    }
  }

  /**
   * Get all the Chat config from build info with defaults.
   *
   * @param FormStateInterface $form_state
   *   The form state to get build info from.
   *
   * @return array
   *   The array of chat config with defaults where required.
   */
  protected function getSearchConfig(FormStateInterface $form_state) {
    $config = $form_state->getBuildInfo()['search_config'] ?? [];
    return $config;
  }

}
