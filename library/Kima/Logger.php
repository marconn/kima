<?php
/**
 * Kima Logger
 * @author Steve Vega
 */
namespace Kima;

use \Kima\Model;

/**
 * Logger
 * Logger for Kima
 */
class Logger extends Model
{

    /**
     * Database engine
     */
    const DB_ENGINE = 'mongo';

    /**
     * Logger default
     */
    const TABLE = 'log';

    /**
     * Log levels
     */
    const INFO = 'information';

    /**
     * Log levels list
     */
    private static $log_levels = [
        self::INFO
    ];

    /**
     * Logs new content into the logger
     */
    public static function log($content, $type = null, $level = null)
    {
        $logger = new self();

        if (!empty($type))
        {
            $logger->set_table($type);
        }

        // set the fields to store
        $level = in_array($level, self::$log_levels) ? $level : self::INFO;
        $fields = ['log_level' => $level, 'log_timestamp' => time()];

        // add custom content, objects will be store as fields
        $content = is_object($content) ? get_object_vars($content) : ['content', $content];
        $fields = array_merge($fields, $content);

        $logger->put($fields);
    }

}
