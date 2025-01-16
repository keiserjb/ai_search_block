<?php

namespace Drupal\ai_search_block\Plugin\Block;

use Drupal\ai_chatbot\Form\ChatForm;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an AI form block.
 *
 * @Block(
 *   id = "ai_search_block",
 *   admin_label = @Translation("AI Search"),
 *   category = @Translation("AI")
 * )
 */
class SearchFormBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected FormBuilderInterface $formBuilder;

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The AI Assistant API runner.
   *
   * @var \Drupal\ai_assistant_api\AiAssistantApiRunner
   */
  protected $aiAssistantRunner;

  /**
   * The file url generator.
   *
   * @var \Drupal\Core\File\FileUrlGenerator
   */
  protected $fileUrlGenerator;

  /**
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   *   The entity display repository.
   */
  protected $entityDisplayRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = new static($configuration, $plugin_id, $plugin_definition);
    $plugin->entityTypeManager = $container->get('entity_type.manager');
    $plugin->formBuilder = $container->get('form_builder');
    $plugin->currentUser = $container->get('current_user');
    $plugin->fileUrlGenerator = $container->get('file_url_generator');
    $plugin->entityDisplayRepository = $container->get('entity_display.repository');
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'placeholder' => 'Ask me a question about your subject here!',
      'submit_text' => 'Ask question',
      'stream' => TRUE,
      'database' => NULL,
      'score_threshold' => 0.6,
      'min_results' => 1,
      'max_results' => 20,
      'output_mode' => 'chunks',
      'rendered_view_mode' => 'full',
      'aggregated_llm' => NULL,
      'access_check' => FALSE,
      'context_threshold' => 0.1,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['form_config'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Form config'),
    ];
    $form['form_config']['placeholder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The placeholder in the form'),
      '#description' => $this->t('The first message to start things of.'),
      '#default_value' => $this->configuration['placeholder'],
    ];
    $form['form_config']['submit_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The submit button text'),
      '#description' => $this->t('The text in the submit button.'),
      '#default_value' => $this->configuration['submit_text'],
    ];
    $form['form_config']['stream'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stream'),
      '#description' => $this->t('Stream the messages in real-time.'),
      '#default_value' => $this->configuration['stream'],
    ];

    $form['source_data'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Source data'),
    ];
    $form['source_data']['database'] = [
      '#type' => 'select',
      '#title' => $this->t('Source database'),
      '#options' => $this->getSearchDatabases(),
      '#default_value' => $this->configuration['database'],
    ];
    $form['rag'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('RAG Settings'),
    ];
    $form['rag']['score_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('RAG threshold'),
      '#description' => $this->t('This is the threshold that the answer have to meet to be thought of as a valid response. Note that the number may shift depending on the similar metric you are using.'),
      '#default_value' => $this->configuration['score_threshold'],
      '#attributes' => [
        'placeholder' => 0.6,
      ],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
    ];

    $min_results = $this->configuration['min_results'];
    $min_results = $min_results ?? 1;

    $form['rag']['min_results'] = [
      '#type' => 'number',
      '#title' => $this->t('RAG minimum results'),
      '#description' => $this->t('The minimum chunks needed to pass the threshold, before leaving a response based on RAG.'),
      '#default_value' => $min_results,
      '#attributes' => [
        'placeholder' => 1,
      ],
    ];
    $max_results = $this->configuration['max_results'];
    $max_results = $max_results ?? 5;

    $form['rag']['max_results'] = [
      '#type' => 'number',
      '#title' => $this->t('RAG max results'),
      '#description' => $this->t('The maximum results that passed the threshold, to take into account.'),
      '#default_value' => $max_results,
      '#attributes' => [
        'placeholder' => 20,
      ],
    ];
    $form['rag']['output_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('RAG context mode'),
      '#description' => $this->t('The context mode for the list given to the Assistant. <br>The <strong>chunk mode</strong> will return the chunk as they are and the LLM will act on this - if chunked correctly this produces very quick answer for chatbots that needs to answer quickly.<br>If you return <strong>aggregated and rendered entities</strong>, there will be an LLM agent first checking each of the answers over the whole entity, and then return an aggregated answer to the Assistant. This is slower, but more accurate.'),
      '#default_value' => $this->configuration['output_mode'],
      '#options' => [
        'chunks' => $this->t('Chunks'),
        'rendered' => $this->t('Aggregated and Rendered entities'),
      ],
    ];

    $options = $this->entityDisplayRepository->getViewModeOptions('node');
    $form['rag']['rendered_view_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('RAG rendered view mode'),
      '#description' => $this->t('Select a preferred view mode. If not found, the default view mode will be used for the given entity type.'),
      '#options' => $options,
      '#default_value' => 'full',
      '#states' => [
        'visible' => [
          ':input[name="[rag][output_mode]"]' => ['value' => 'rendered'],
        ],
      ],
    ];

    $default_prompt = $this->t('Can you summarize if the following article(s) are relevant to the question?
If it is not, please just answer "no answer".
If it is, answer with the details that are needed to answer this from a larger perspective.

The question is:
-----------------------
[question]
-----------------------

The article(s) are:
-----------------------
[entity]
-----------------------');

    $form['rag']['aggregated_llm'] = [
      '#type' => 'textarea',
      '#title' => $this->t('RAG LLM Agent'),
      '#description' => $this->t('With Aggregated and Rendered entities, this agent will take each of the entities returned and create one summarized answer to feed to the assistant. This can take the tokens [question] and [entity] or even specific tokens from the entity below. If multiple results are found the [entity] will be replaced with the contents of multiple results separated by --------- and new lines.'),
      '#default_value' => $this->configuration['aggregated_llm'] ?? $default_prompt,
      '#attributes' => [
        'rows' => 10,
        'placeholder' => $default_prompt,
      ],
      '#states' => [
        'visible' => [
          ':input[name="[rag][output_mode]"]' => ['value' => 'rendered'],
        ],
      ],
    ];

    $form['rag']['access_check'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('RAG access check'),
      '#description' => $this->t('With this enabled the system will do a post query access check on every chunk to see if the user has access to that content. Note that this might lead to no results and be slower, but it makes sure that none-accessible items are not reached. This is done before the Assistant prompt, so its secure to prompt injection.'),
      '#default_value' => $this->configuration['access_check'],
    ];

    $form['rag']['context_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Context threshold'),
      '#description' => $this->t('This is the threshold that the answer have to meet to be thought of as a valid response in context. Note that the similarity value is generally lower on a specific question in context, so lower values are needed.'),
      '#default_value' => $this->configuration['context_threshold'],
      '#attributes' => [
        'placeholder' => 0.1,
      ],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#states' => [
        'visible' => [
          ':input[name="use_context"]' => ['checked' => TRUE],
        ],
      ],
    ];


    return $form;
  }

  /**
   * Get all search databases.
   */
  private function getSearchDatabases(): array {
    $databases = [];
    $databases[''] = $this->t('-- Select --');
    $indices = $this->entityTypeManager->getStorage('search_api_index')->loadMultiple();
    foreach ($indices as $index) {
      $databases[$index->id()] = $index->label() . ' (' . $index->id() . ')';
    };
    return $databases;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['placeholder'] = $form_state->getValue('form_config')['placeholder'];
    $this->configuration['submit_text'] = $form_state->getValue('form_config')['submit_text'];
    $this->configuration['stream'] = $form_state->getValue('form_config')['stream'];
    $this->configuration['database'] = $form_state->getValue('source_data')['database'];
    $this->configuration['score_threshold'] = $form_state->getValue('rag')['score_threshold'];
    $this->configuration['min_results'] = $form_state->getValue('rag')['min_results'];
    $this->configuration['max_results'] = $form_state->getValue('rag')['max_results'];
    $this->configuration['output_mode'] = $form_state->getValue('rag')['output_mode'];
    $this->configuration['rendered_view_mode'] = $form_state->getValue('rag')['rendered_view_mode'];
    $this->configuration['aggregated_llm'] = $form_state->getValue('rag')['aggregated_llm'];
    $this->configuration['access_check'] = $form_state->getValue('rag')['access_check'];
    $this->configuration['context_threshold'] = $form_state->getValue('rag')['context_threshold'];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
//    $assistant = $this->entityTypeManager->getStorage('ai_assistant')->load($this->configuration['ai_assistant']);
//    $this->aiAssistantRunner->setAssistant($assistant);
//    // Check if the assistant is setup and that the user has access to it.
//    if (!$this->aiAssistantRunner->isSetup() || !$this->aiAssistantRunner->userHasAccess()) {
//      return [];
//    }
//    $this->aiAssistantRunner->streamedOutput($this->configuration['stream']);
//    $block = [];
//
//    $block['#theme'] = 'ai_search_block';
//    $block['#attached']['library'][] = 'ai_search_block/chat';
//    $block['#settings'] = $this->configuration;
//    $block['#attached']['drupalSettings']['ai_search_block']['placeholder'] = $this->configuration['placeholder'];
//    $block['#attached']['drupalSettings']['ai_search_block']['submit_text'] = $this->configuration['submit_text'];
//    $user = $this->currentUser->getAccount();
//    // Override username if the user is authenticated and configured.
//    if ($user->isAuthenticated() && $this->configuration['use_username']) {
//      $block['#attached']['drupalSettings']['ai_search_block']['default_username'] = $user->getDisplayName();
//    }
//    // Override avatar if the user is authenticated and configured and exist.
//    if ($user->isAuthenticated() && $this->configuration['use_avatar']) {
//      $userEntity = $this->entityTypeManager->getStorage('user')->load($user->id());
//      if (!empty($userEntity->user_picture->entity)) {
//        $block['#attached']['drupalSettings']['ai_search_block']['default_avatar'] = $this->fileUrlGenerator->generateAbsoluteString($userEntity->user_picture->entity->getFileUri());
//      }
//    }
    $block = [];
    $form_state = new FormState();
    $form_state->addBuildInfo('block_id', $this->getPluginId());
    $form = $this->formBuilder->buildForm(ChatForm::class, $form_state);
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => '',
      '#default_value' => '',
      '#attributes' => [
        'placeholder' => $this->configuration['placeholder'],
      ],
      '#required' => TRUE,
    ];
    $form['submit'] = [
      '#name' => 'change_connection_type',
      '#type' => 'submit',
      '#value' => $this->configuration['submit_text'],
    ];

    $block['#theme'] = 'ai_search_block';
    //$block['#attached']['library'][] = 'ai_chatbot/chat';
    $block['#header'] = $this->configuration['label'];
    $block['#rendered_form'] = 'TEST';
    $block['#output'] = 'OUTPUT';

    // Set the settings first, since they are needed to render the message.
//    $block['#attached']['drupalSettings']['ai_chatbot']['bot_name'] = $this->configuration['bot_name'];
//    $block['#attached']['drupalSettings']['ai_chatbot']['bot_image'] = $this->configuration['bot_image'];
//    $block['#attached']['drupalSettings']['ai_chatbot']['default_username'] = $username;
//    $block['#attached']['drupalSettings']['ai_chatbot']['default_avatar'] = $avatar;
//    $block['#attached']['drupalSettings']['ai_chatbot']['toggle_state'] = $this->configuration['toggle_state'];
//    $block['#attached']['drupalSettings']['ai_chatbot']['output_type'] = $this->configuration['output_type'];
//    $block['#attached']['drupalSettings']['ai_chatbot']['first_message'] = $this->configuration['first_message'];
//    $block['#attached']['drupalSettings']['ai_chatbot']['has_history'] = $has_history;

    return $block;
//    return [
//      '#markup' => $this->t('Hello, AI World!'),
//    ];
    //return $block;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
