<?php

namespace Drupal\ai_search_block\Plugin\Block;

use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ai_search_block\Form\SearchForm;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGenerator;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
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
   * @var EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The form builder.
   *
   * @var FormBuilderInterface
   */
  protected FormBuilderInterface $formBuilder;

  /**
   * Current user.
   *
   * @var AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The AI Assistant API runner.
   *
   * @var AiAssistantApiRunner
   */
  protected $aiAssistantRunner;

  /**
   * The file url generator.
   *
   * @var FileUrlGenerator
   */
  protected $fileUrlGenerator;

  /**
   * @var EntityDisplayRepositoryInterface
   *   The entity display repository.
   */
  protected $entityDisplayRepository;

  /**
   * @var
   *   The ai provider manager.
   */
  protected $aiProviderManager;

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
    $plugin->aiProviderManager = $container->get('ai.provider');
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'placeholder' => 'Ask me a question about your subject here!',
      'submit_text' => 'Ask question',
      'loading_text' => 'Loading',
      'stream' => TRUE,
      'database' => NULL,
      'score_threshold' => 0.6,
      'min_results' => 1,
      'max_results' => 20,
      'output_mode' => 'chunks',
      'rendered_view_mode' => 'full',
      'llm_model' => NULL,
      'aggregated_llm' => NULL,
      'access_check' => 'post',
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
    $form['form_config']['loading_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The "Loading" text'),
      '#description' => $this->t('The text one sees while waiting for the result'),
      '#default_value' => $this->configuration['loading_text'],
    ];
    $form['form_config']['suffix_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('The "Suffix" text'),
      '#description' => $this->t('The text one sees below the results. This may be html'),
      '#default_value' => $this->configuration['suffix_text'],
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
      '#description' => $this->t('The context mode for the list given. <br>The <strong>chunk mode</strong> will return the chunk as they are and the LLM will act on this - if chunked correctly this produces very quick answer for chatbots that needs to answer quickly.<br>If you return <strong>aggregated and rendered entities</strong>, there will be an LLM agent first checking each of the answers over the whole entity, and then return an aggregated answer. This is slower, but more accurate.'),
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

    $llm_model_options = $this->aiProviderManager->getSimpleProviderModelOptions('chat');
    array_shift($llm_model_options);
    array_splice($llm_model_options, 0, 1);
    $form['rag']['llm_model'] = [
      '#type' => 'select',
      "#empty_option" => $this->t('-- Default from AI module (chat) --'),
      '#title' => $this->t('RAG LLM Model'),
      '#default_value' => $this->configuration['llm_model'],
      '#options' => $llm_model_options,
      '#description' => $this->t('Select which provider to use for this plugin. See the <a href=":link">Provider overview</a> for details about each provider.', [':link' => '/admin/config/ai/providers']),
    ];

    $default_prompt = $this->t('ALWAYS RESPOND IN HTML.
Answer the users question (see QUESTION) using the articles below (See ARTICLES).
Your first language is dutch. iF the user asks the question in another language you may switch to that language.
Never repeat the question. no pleasantries, just a dry response based on the articles.
Always add the URI to the used resource in the snippet or below the response.

Today: [date_today]
Tomorrow: [date_tomorrrow]
Yesterday: [date_yesterday]
The current time: [time_now]

QUESTION:
-----------------------
[question]
-----------------------

ARTICLES:
-----------------------
[entity]
-----------------------

Conserning the output format:
The articles are formatted as Markdown. Transform this to HTML.
You can use simple HTML structures like <b><h3><i><li> and <a>.
Wrap links in a <a> element, return lists in a <ul><li>
You can also reformat Markdown as HTML.
Always add the URI to the used resource in the snippet or below the response.

Example response:
```html
<h3>Example title<h3>
<p>This is a textual rsponse with a <a href="">link</a>.<p>
```
(respond like examples but without the starting ```html and trailing ```).
');

    $form['rag']['aggregated_llm'] = [
      '#type' => 'textarea',
      '#title' => $this->t('RAG LLM Agent'),
      '#description' =>  $this->t('With Aggregated and Rendered entities, this agent will take each of the entities returned and create one summarized answer to feed to the assistant. This can take the tokens [question] and [entity] or even specific tokens from the entity below. If multiple results are found the [entity] will be replaced with the contents of multiple results separated by --------- and new lines.<br><br><strong>The following placesholders can be used:</strong><br>
      <em>[is_logged_in]</em> - A message if the person is logged in or not.<br>
      <em>[user_name]</em> - The username of the user.<br>
      <em>[user_roles]</em> - The roles of the user.<br>
      <em>[user_id]</em> - The user id of the user.<br>
      <em>[user_language]</em> - The language of the user.<br>
      <em>[user_timezone]</em> - The timezone of the user.<br>
      <em>[page_path]</em> - The path of the page.<br>
      <em>[page_language]</em> - The language of the page.<br>
      <em>[site_name]</em> - The name of the site.<br>
      <em>[date_today]</em> - Today.<br>
      <em>[date_yesterday]</em> - Yesterday.<br>
      <em>[date_tomorrrow]</em> - Tomorrow.<br>
      <em>[time_now]</em> - The current time.<br>
      '),
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

    $access_options = [];
    $access_options['false'] = $this->t('No access check');
    $access_options['meta'] = $this->t('[NOT WORKING YET] filter permission in metadata');
    $access_options['post'] = $this->t('Post (after) lookup access check');
    $access_options['view'] = $this->t('[NOT WORKING YET] Only content from a view');
    $form['rag']['access_check'] = [
      '#type' => 'select',
      '#options' => $access_options,
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
    }
    return $databases;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['placeholder'] = $form_state->getValue('form_config')['placeholder'];
    $this->configuration['submit_text'] = $form_state->getValue('form_config')['submit_text'];
    $this->configuration['stream'] = $form_state->getValue('form_config')['stream'];
    $this->configuration['loading_text'] = $form_state->getValue('form_config')['loading_text'];
    $this->configuration['suffix_text'] = $form_state->getValue('form_config')['suffix_text'];
    $this->configuration['database'] = $form_state->getValue('source_data')['database'];
    $this->configuration['score_threshold'] = $form_state->getValue('rag')['score_threshold'];
    $this->configuration['min_results'] = $form_state->getValue('rag')['min_results'];
    $this->configuration['max_results'] = $form_state->getValue('rag')['max_results'];
    $this->configuration['output_mode'] = $form_state->getValue('rag')['output_mode'];
    $this->configuration['rendered_view_mode'] = $form_state->getValue('rag')['rendered_view_mode'];
    $this->configuration['aggregated_llm'] = $form_state->getValue('rag')['aggregated_llm'];
    $this->configuration['access_check'] = $form_state->getValue('rag')['access_check'];
    $this->configuration['context_threshold'] = $form_state->getValue('rag')['context_threshold'];
    $this->configuration['llm_model'] = $form_state->getValue('rag')['llm_model'];

    //llm_model
    if (method_exists($form_state->getBuildInfo()['callback_object'], 'getEntity')) {
      // Likely this is the stock drupal block layout config.
      $this->configuration['block_id'] = $form_state->getBuildInfo()['callback_object']->getEntity()->id();
      $this->configuration['block_offset'] = '';
    }
    else {
      $callback_obj = $form_state->getBuildInfo()['callback_object'];
      // Likely this is Layout builder
      $current_component = $callback_obj->getCurrentComponent();
      $uuid = $current_component->getUuid();
      $region = $current_component->getRegion();
      $weight = $current_component->getWeight();

      $layout_offset = $weight . '/' . $region;

      $this->configuration['block_id'] = $uuid;
      $this->configuration['block_offset'] = $layout_offset;
    }

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
    $block = [];
    $block['#settings'] = $this->configuration;
//    $block['#attached']['drupalSettings']['ai_search_block']['placeholder'] = $this->configuration['placeholder'];
    $url = Url::fromRoute('ai_search_block.api', [], ['absolute' => FALSE]);
    $block['#attached']['drupalSettings']['ai_search_block']['submit_url'] = $url->toString();

    if (!isset($this->configuration['loading_text'])) {

    }
    $block['#attached']['drupalSettings']['ai_search_block']['loading_text'] = $this->configuration['loading_text'];
    $block['#attached']['drupalSettings']['ai_search_block']['suffix_text'] = $this->configuration['suffix_text'];

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
    $form_state = new FormState();
    $form_state
      ->addBuildInfo('block_id', $this->getPluginId())
      ->addBuildInfo('search_config', $this->configuration);
    $form = $this->formBuilder->buildForm(SearchForm::class, $form_state);
    $block['#theme'] = 'ai_search_block_wrapper';
    $block['#attached']['library'][] = 'ai_search_block/ai_search_block';
    $block['#rendered_form'] = $form;
    //$block['#cache']['max-age'] = 0;
    $block['#output'] = ' ';
    // Set the settings first, since they are needed to render the message.
//    $block['#attached']['drupalSettings']['ai_chatbot']['bot_name'] = $this->configuration['bot_name'];
//    $block['#attached']['drupalSettings']['ai_chatbot']['bot_image'] = $this->configuration['bot_image'];
//    $block['#attached']['drupalSettings']['ai_chatbot']['default_username'] = $username;
//    $block['#attached']['drupalSettings']['ai_chatbot']['default_avatar'] = $avatar;
//    $block['#attached']['drupalSettings']['ai_chatbot']['toggle_state'] = $this->configuration['toggle_state'];
//    $block['#attached']['drupalSettings']['ai_chatbot']['output_type'] =fgetCacheMaxAge $this->configuration['output_type'];
//    $block['#attached']['drupalSettings']['ai_chatbot']['first_message'] = $this->configuration['first_message'];
//    $block['#attached']['drupalSettings']['ai_chatbot']['has_history'] = $has_history;
    return $block;
  }

}
