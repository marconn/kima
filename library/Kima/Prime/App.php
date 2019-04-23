<?php
namespace Kima\Prime;

use Kima\Error;
use Kima\Http\Request;
use DDTrace\Bootstrap;
use DDTrace\Tag;
use DDTrace\Tracer;
use DDTrace\Type;

/**
 * Kima Prime App
 * Entry point for apps using Kima with the front controller pattern
 * Example: App::get_instance()->run(['/' => 'Index']);
 */
class App
{

    /**
     * instance
     * @var \Kima\Base\App
     */
    private static $instance;

    /**
     * Folder paths
     */
    private $application_folder;
    private $controller_folder;
    private $module_folder;
    private $view_folder;
    private $l10n_folder;

    /**
     * config
     * @var array
     */
    private $config;

    /**
     * module
     * @var string
     */
    private $module;

    /**
     * controller
     * @var string
     */
    private $controller;

    /**
     * method
     * @var string
     */
    private $method;

    /**
     * Current request language
     * @var string
     */
    private $language;

    /**
     * Default time zone
     * @var string
     */
    private $time_zone;

    /**
     * Whether the connection is secure or not
     * @var boolean
     */
    private $is_https;

    /**
     * Enforces the controller to be https
     * @var boolean
     */
    private $enforce_https = false;

    /**
     * Application predispatcher class
     * @var string
     */
    private $predispatcher;

    /**
     * Individual controllers that should be always https
     * @var array
     */
    private $https_controllers = [];

    /**
     * Sets the base position for the url routes
     * @var integer
     */
    private $url_base_pos = 0;

    /**
     * Tracer instance
     *
     * @var Tracer
     */
    private $tracer;

    /**
     * Construct
     */
    private function __construct()
    {
        $this->set_application_folders();
    }

    /**
     * Get the application instance
     * @return App
     */
    public static function get_instance()
    {
        isset(self::$instance) || self::$instance = new self;

        return self::$instance;
    }

    /**
     * Setup the basic application config
     * @param  string $custom_config a custom config file
     * @return App
     */
    public function setup($custom_config = null)
    {
        // get the module and HTTP method
        switch (true) {
            case getenv('MODULE'):
                $module = getenv('MODULE');
                break;
            case !empty($_SERVER['MODULE']):
                $module = $_SERVER['MODULE'];
                break;
            default:
                $module = null;
        }
        $method = strtolower(Request::get_method());

        // set module, controller and action
        $this->set_module($module);
        $this->set_method($method);
        $this->set_is_https();

        // set the config
        $this->set_config($custom_config);

        // set the default language
        $lang_config = $this->get_config()->get('language');
        if (isset($lang_config) && isset($lang_config['default'])) {
            $this->set_language($lang_config['default']);
        }

        $this->setup_datadog($this->get_config());

        return $this;
    }

    /**
     * Run the application
     * @param  array  $urls
     * @param  string $custom_config a custom config file
     * @return Action
     */
    public function run(array $urls, $custom_config = null)
    {
        $this->setup($custom_config);

        $action = (new Action($urls))->run();

        $this->get_tracer()->getActiveSpan()->finish();
        $this->get_tracer()->flush();

        return $action;
    }

    /**
     * Return the application config
     * @return Config
     */
    public function get_config()
    {
        return $this->config;
    }

    /**
     * Set the config
     * @param  string $custom_config
     * @return App
     */
    public function set_config($custom_config = null)
    {
        $this->config = new Config($custom_config);

        return $this;
    }

    /**
     * Returns the application module
     * @return string
     */
    public function get_module()
    {
        return $this->module;
    }

    /**
     * Set the application module
     * @param  string $module
     * @return App
     */
    public function set_module($module)
    {
        $this->module = (string)$module;

        return $this;
    }

    /**
     * Return the application controller
     * @return string
     */
    public function get_controller()
    {
        return $this->controller;
    }

    /**
     * Set the application controller
     * @param  string $controller
     * @return App
     */
    public function set_controller($controller)
    {
        $this->controller = (string)$controller;

        return $this;
    }

    /**
     * Returns the application method
     * @return string
     */
    public function get_method()
    {
        return $this->method;
    }

    /**
     * Sets the method
     * @param  string $method
     * @return App
     */
    public function set_method($method)
    {
        $this->method = (string)$method;

        return $this;
    }

    /**
     * Returns the app tracer
     * @return Tracer
     */
    public function get_tracer()
    {
        return $this->tracer;
    }

    /**
     * Sets the app tracer
     * @param Tracer $tracer
     * @return App
     */
    public function set_tracer($tracer)
    {
        $this->tracer = $tracer;

        return $this;
    }

    /**
     * Returns the url base position for routing
     * @return int
     */
    public function get_url_base_pos()
    {
        return $this->url_base_pos;
    }

    /**
     * Sets the url routes starting position
     * @param  int $url_base_pos
     * @return App
     */
    public function set_url_base_pos($url_base_pos)
    {
        $this->url_base_pos = (string)$url_base_pos;

        return $this;
    }

    /**
     * Return the application language
     * @return string
     */
    public function get_language()
    {
        return $this->language;
    }

    /**
     * Sets the language
     * @param  string $language
     * @return App
     */
    public function set_language($language)
    {
        $this->language = (string)$language;

        return $this;
    }

    /**
     * Sets the default time zone
     * @param  string $time_zone
     * @return App
     */
    public function set_time_zone($time_zone)
    {
        $this->time_zone = (string)$time_zone;

        return $this;
    }

    /**
     * Gets the application default time zone
     * @return string
     */
    public function get_time_zone()
    {
        return empty($this->time_zone)
            ? date_default_timezone_get()
            : $this->time_zone;
    }

    /**
     * Returns whether is a secure connection or not
     * @return boolean
     */
    public function is_https()
    {
        return $this->is_https;
    }

    /**
     * Set whether the connections is https or not
     * @return App
     */
    private function set_is_https()
    {
        // get values from sever
        $https = Request::server('HTTPS');
        $port = Request::server('SERVER_PORT');

        // check if https is on
        $this->is_https = (!empty($https) && 'off' !== $https || 443 == $port);

        return $this;
    }

    /**
     * Makes all request https by default
     * @return App
     */
    public function enforce_https()
    {
        $this->enforce_https = true;

        return $this;
    }

    /**
     * Returns whether the request should be https or not
     * @return boolean
     */
    public function is_https_enforced()
    {
        return $this->enforce_https;
    }

    /**
     * Sets the controllers that should be always https
     * @param  array $controllers
     * @return App
     */
    public function set_https_controllers(array $controllers)
    {
        $this->https_controllers = $controllers;

        return $this;
    }

    /**
     * Gets the controllers that should be always https
     * @return array
     */
    public function get_https_controllers()
    {
        return $this->https_controllers;
    }

    /**
     * Set an http error for the page
     * @param int $status_code
     */
    public function set_http_error($status_code)
    {
        $active_span = $this->get_tracer()->getActiveSpan();

        if (isset($active_span)) {
            $active_span->setTag(Tag::HTTP_STATUS_CODE, $status_code);
        }
        // set the status code
        http_response_code($status_code);

        $error_path = 'Controller\\Error';

        $module = $this->get_module();
        if (!empty($module)) {
            $error_path = 'Module\\' . ucfirst(strtolower($module)) . '\\' . $error_path;
        }

        $this->set_controller('Error')->set_method('get');
        $controller = new $error_path();
        $controller->get();
        exit;
    }

    /**
     * Sets the predispatcher class
     * @param  string $predispatcher
     * @return App
     */
    public function set_predispatcher($predispatcher)
    {
        $this->predispatcher = (string)$predispatcher;

        return $this;
    }

    /**
     * Gets the predispatcher class
     * @return string
     */
    public function get_predispatcher()
    {
        return $this->predispatcher;
    }

    /**
     * Gets the application_folder
     * @return string
     */
    public function get_application_folder()
    {
        return $this->application_folder;
    }

    /**
     * Gets the controller_folder
     * @return string
     */
    public function get_controller_folder()
    {
        return $this->controller_folder;
    }

    /**
     * Gets the module_folder
     * @return string
     */
    public function get_module_folder()
    {
        return $this->module_folder;
    }

    /**
     * Gets the view_folder
     * @return string
     */
    public function get_view_folder()
    {
        return $this->view_folder;
    }

    /**
     * Gets the l10n_folder
     * @return string
     */
    public function get_l10n_folder()
    {
        return $this->l10n_folder;
    }

    /**
     * Sets the application folders
     * @return App
     */
    private function set_application_folders()
    {
        $this->application_folder = ROOT_FOLDER . '/application/';
        $this->controller_folder = $this->application_folder . 'controller/';
        $this->module_folder = $this->application_folder . 'module/';
        $this->view_folder = $this->application_folder . 'view/';
        $this->l10n_folder = ROOT_FOLDER . '/resource/l10n/';

        return $this;
    }

    /** Initializes tracing
     * @param Config $config
     */
    private function setup_datadog($config)
    {
        $tracing_config = $config->get('tracing');
        $operation = 'web.request';
        $service_name = 'php';
        $tracing_enabled = false;

        if (isset($tracing_config) && isset($tracing_config['enabled'])) {
            $tracing_enabled = (bool)$tracing_config['enabled'];
        }

        if ($tracing_enabled) {
            // get operation name
            if (isset($tracing_config) && isset($tracing_config['weboperation']) && isset($tracing_config['weboperation']['name'])) {
                $operation = $tracing_config['weboperation']['name'];
                // Required to disable automatic instrumentation. We should figure out a better way later
                Bootstrap::resetTracer();
            }

            if (isset($tracing_config) && isset($tracing_config['webservice']) && isset($tracing_config['webservice']['name'])) {
                $service_name = $tracing_config['webservice']['name'];
            }
        }

        $config = [
            /**
             * ServiceName specifies the name of this application.
             */
            'service_name' => $service_name,
            /**
             * Enabled, when false, returns a no-op implementation of the Tracer.
             */
            'enabled' => $tracing_enabled,
            /**
             * GlobalTags holds a set of tags that will be automatically applied to
             * all spans.
             */
            'global_tags' => [
                Tag::SPAN_TYPE => Type::WEB_SERVLET
            ]
        ];

        $this->set_tracer(new Tracer(null, null, $config));

        $this->get_tracer()->startActiveSpan($operation);
    }
}