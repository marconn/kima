<?php
/**
 * Kima Action
 * @author Steve Vega
 */
namespace Kima;

use \Kima\Error,
    \Kima\Controller,
    \Kima\Http\Redirector,
    \Kima\Http\Request,
    \Kima\Http\StatusCode,
    \Bootstrap;

/**
 * Action
 * Implementation of the Front Controller design pattern
 */
class Action
{

    /**
     * Error messages
     */
    const ERROR_NO_BOOTSTRAP = 'Class Boostrap not defined in Bootstrap.php';
    const ERROR_NO_PREDISPATCHER = 'Registered Predispatcher class %s is not accesible';
    const ERROR_NO_CONTROLLER_FILE = 'Class file for "%s" is not accesible on "%s"';
    const ERROR_NO_CONTROLLER_CLASS = ' Class "%s" not declared on "%s"';
    const ERROR_NO_CONTROLLER_INSTANCE = 'Object for "%s" is not an instance of \Kima\Controller';
    const ERROR_NO_MODULE_ROUTES = 'Routes for module "%s" are not set';

    /**
     * Bootstrap path
     */
    const BOOTSTRAP_PATH = 'Bootstrap.php';

    /**
     * Url parameters
     * @var array
     */
    private $url_parameters = [];

    /**
     * Construct
     * @param array $urls
     */
    public function __construct(array $urls)
    {
        // load the bootstrap
        $this->load_bootstrap();

        // set the url parameters
        $this->set_url_parameters();

        // set the application language
        $app = Application::get_instance();
        $language = $this->get_language();
        $app->set_language($language);

        // set the module routes if exists
        $module = $app->get_module();
        if (!empty($module))
        {
            array_key_exists($module, $urls)
                ? $urls = $urls[$module]
                : Error::set(sprintf(self::ERROR_NO_MODULE_ROUTES, $module));
        }

        // get the controller matching the routes
        $controller = $this->get_controller($urls);

        // validate controller and action
        if (empty($controller))
        {
            $app->set_http_error(404);
            return;
        }

        // set the action controller
        $app->set_controller($controller);

        // run the predispatcher
        $this->load_predispatcher();

        // check for https/http redirections
        $this->check_https($controller);

        // inits the controller action
        $this->run_action($controller);
    }


    /**
     * Gets the url match route to follow
     * Returns the required controller to process the action
     * @param array $urls
     * @return string
     */
    private function get_controller(array $urls)
    {
        // gets the URL path needed parameters
        $url_parameters = $this->get_url_parameters();
        $url_parameters_count = count($url_parameters);

        // loop the defined urls looking for a match
        foreach ($urls as $url => $controller)
        {
            if (is_string($controller))
            {
                // split the url elements
                $url_elements = array_values(array_filter(explode('/', $url)));

                // compare the elements size
                if ($url_parameters_count === count($url_elements))
                {
                    $is_match = true;

                    // loop each url elements
                    foreach ($url_elements as $key => $url_element)
                    {
                        // match the url element with the path element
                        preg_match('/^' . $url_element . '$/', $url_parameters[$key], $matches);
                        if (!$matches)
                        {
                            $is_match = false;
                            break;
                        }
                    }

                    // if all the elements matched, return its controller
                    if ($is_match)
                    {
                        return $controller;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Checks for possible http/https redirections
     * @param  string $controller
     */
    private function check_https($controller)
    {
        // get whether we are currently on https or not
        $application = Application::get_instance();
        $is_https = $application->is_https();

        // check if https is enforced
        $is_https_enforced = $application->is_https_enforced();
        if ($is_https_enforced)
        {
            return $is_https ? true : Redirector::https();
        }

        // check if the controller is in the individual list of https request
        $https_controllers = $application->get_https_controllers();
        if (in_array($controller, $https_controllers))
        {
            return $is_https ? true : Redirector::https();
        }

        // if we are on https but shouldn't redirect to http
        if ($is_https && !in_array($controller, $https_controllers))
        {
            Redirector::http();
        }
    }

    /**
     * Runs an application action
     * @param string $controller
     */
    private function run_action($controller)
    {
        // get the application values
        $application = Application::get_instance();
        $config = $application->get_config();
        $module = $application->get_module();
        $method = $application->get_method();

        // get the controller path
        $controller_folder = $module
            ? $config->module['folder'] . '/' . $module . '/controller'
            : $config->controller['folder'];

        $controller_path = $controller_folder . '/' . $controller . '.php';

        // get the controller class
        $controller_class = '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $controller);

        $controller_obj = $this->get_controller_instance($controller_class, $controller_path);

        // validate-call action
        $methods = $this->get_controller_methods($controller_class);
        if (!in_array($method, $methods))
        {
            $application->set_http_error(405);
            return;
        }

        $params = $this->get_url_parameters();
        $controller_obj->$method($params);
    }

    /**
     * Gets the controller instance
     * @param string $controller The controller name
     * @param string $controller_path
     * @return \Kima\Controller
     */
    private function get_controller_instance($controller, $controller_path)
    {
        // require the controller file
        if (is_readable($controller_path))
        {
            require_once $controller_path;
        }
        else
        {
            Error::set(sprintf(self::ERROR_NO_CONTROLLER_FILE, $controller, $controller_path));
            return;
        }

        // validate-create controller object
        class_exists($controller, false)
            ? $controller_obj = new $controller
            : Error::set(sprintf(self::ERROR_NO_CONTROLLER_CLASS, $controller, $controller_path));

        // validate controller is instance of Kima\Controller
        if (!$controller_obj instanceof Controller)
        {
            Error::set(sprintf(self::ERROR_NO_CONTROLLER_INSTANCE, $controller));
        }

        return $controller_obj;
    }

    /**
     * gets the controller available methods
     * removes the parent references
     * @param string $controller
     * @return array
     */
    private function get_controller_methods($controller)
    {
        $parent_methods = get_class_methods('Kima\Controller');
        $controller_methods = get_class_methods($controller);

        return array_diff($controller_methods, $parent_methods);
    }

    /**
     * gets the language required for the current action
     * @return string
     */
    public function get_language()
    {
        // get the possible language
        $url_parameters = $this->get_url_parameters();
        $language = array_shift($url_parameters);
        $languages = Application::get_instance()->get_available_languages();

        // get the default language
        $default_language = Application::get_instance()->get_default_language();

        // return the desired languages
        if (in_array($language, $languages) && $default_language !== $language)
        {
            array_shift($this->url_parameters);
        }
        else
        {
            $language = $default_language;
        }

        return $language;
    }

    /**
     * Sets the url parameters
     */
    public function set_url_parameters()
    {
        $path_parts = explode('?', Request::server('REQUEST_URI'));
        $path = array_shift($path_parts);
        $path_elements = array_values(array_filter(explode('/', $path)));

        $this->url_parameters = $path_elements;
        return $this;
    }

    /**
     * Gets the url parameters
     * @return array
     */
    public function get_url_parameters()
    {
        return $this->url_parameters;
    }

    /**
     * Loads the application bootstrap
     * Calls all public methods on it
     */
    private function load_bootstrap()
    {
        $app = Application::get_instance();
        $config = $app->get_config();

        // set module path if exists
        $module = $app->get_module();
        $module_path = !empty($module)
            ? 'module' . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR
            : '';

        // set the bootstrap path
        $bootstrap_path = $config->application['folder'] . DIRECTORY_SEPARATOR
            . $module_path . self::BOOTSTRAP_PATH;

        // load the bootstrap if available
        if (is_readable($bootstrap_path))
        {
            // get the bootstrap and make sure the class exists
            require_once $bootstrap_path;
            if (!class_exists('Bootstrap', false))
            {
                Error::set(self::ERROR_NO_BOOTSTRAP);
            }

            // get the bootstrap methods and call them
            $methods = get_class_methods('Bootstrap');
            $bootstrap = new Bootstrap();
            foreach($methods as $method)
            {
                $bootstrap->{$method}();
            }
        }
    }

    /**
     * Loads predispatcher class
     * This will run just before the controller action is fired
     */
    private function load_predispatcher()
    {
        $predispatcher = Application::get_instance()->get_predispatcher();

        if (!empty($predispatcher))
        {
            // get the bootstrap and make sure the class exists
            if (!class_exists($predispatcher))
            {
                Error::set(sprintf(self::ERROR_NO_PREDISPATCHER, $predispatcher));
            }

            // get the bootstrap methods and call them
            $methods = get_class_methods($predispatcher);
            $predispatcher = new $predispatcher();
            foreach($methods as $method)
            {
                $predispatcher->{$method}();
            }
        }
    }

}