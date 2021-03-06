<?php
namespace Germania\Logger;

use Pimple\Container;
use Pimple\ServiceProviderInterface;

use Monolog\Logger as Monolog;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\NullHandler;
use Monolog\Processor\WebProcessor;
use Bramus\Monolog\Formatter\ColoredLineFormatter;



class LoggerServiceProvider implements ServiceProviderInterface
{

    public $logname;
    public $loglevel;
    public $logfile;
    public $logfile_count = 0;
    public $is_dev;
    public $log_runtime;


    /**
     * @param string  $logname        The default Logger channel name
     * @param string  $logfile        The logfile to use
     * @param int     $loglevel       The log level
     * @param bool    $is_dev         Wether this environment is "Development" or not
     * @param bool    $log_runtime    Wether to log the script runtime
     * @param bool    $logfile_count  Maximum count of rotating logfiles. Defaults to 0 (indefinite)
     */
    public function __construct( $logname, $logfile, $loglevel, $is_dev, $log_runtime, $logfile_count = null )
    {
        $this->logname       = $logname;
        $this->logfile       = $logfile;
        $this->logfile_count = $logfile_count ?: $this->logfile_count;
        $this->loglevel      = $loglevel;
        $this->is_dev        = (bool) $is_dev;
        $this->log_runtime   = (bool) $log_runtime;
    }



    /**
     * @implements ServiceProviderInterface
     */
    public function register(Container $dic)
    {


        // ------------------------------------------
        // Configuration stuff
        // ------------------------------------------



        $dic['Logger.loglevel'] = function($dic) {
            return $this->loglevel;
        };

        $dic['Logger.logfile'] = function($dic) {
            return $this->logfile;
        };

        $dic['Logger.logfile_count'] = function($dic) {
            return $this->logfile_count;
        };

        $dic['Logger.do_log_runtime'] = function($dic) {
            return $this->log_runtime;
        };

        $dic['Logger.is_dev'] = function($dic) {
            return $this->is_dev;
        };

        $dic['Logger.name'] = function($dic) {
            return $this->logname;
        };

        /**
         * @return array
         */
        $dic['Logger.Environment'] = function($dic) {
            return $_SERVER;
        };



        // ------------------------------------------
        // Monolog Handlers
        // ------------------------------------------


        /**
         * @return  RotatingFileHandler
         */
        $dic['Logger.Handler.RotatingFiles'] = function($dic) {
            $loglevel      = $dic['Logger.loglevel'];
            $logfile       = $dic['Logger.logfile'];
            $logfile_count = $dic['Logger.logfile_count'];

            // Writeable check
            if ($logdir = dirname($logfile)
            and !is_dir( $logdir)
            and !mkdir($logdir, 0775, "recursive")):
                http_response_code( 500 );
                die("Could not create logfile directory.");
            endif;

            if (!is_writable( $logdir)):
                http_response_code( 500 );
                die("Logfile directory not writeable.");
            endif;

            return new RotatingFileHandler($logfile, $logfile_count, $loglevel);
        };



        /**
         * @return StreamHandler|NullHandler
         */
        $dic['Logger.Handler.StdErr'] = function($dic) {
            $loglevel = $dic['Logger.loglevel'];
            $is_dev   = $dic['Logger.is_dev'];

            if ($is_dev):
                $stderr_handler = new StreamHandler('php://stderr', $loglevel);
                $stderr_handler->setFormatter(new ColoredLineFormatter());
                return $stderr_handler;
            endif;

            return new NullHandler( $loglevel );

        };


        /**
         * @return array
         */
        $dic['Logger.Handlers'] = function($dic) {
            return [
                $dic['Logger.Handler.RotatingFiles'],
                $dic['Logger.Handler.StdErr']
            ];
        };




        // ------------------------------------------
        // Monolog Processors
        // ------------------------------------------



        /**
         * @return array
         */
        $dic['Logger.Processor.Web.fields'] = function($dic) {
            return [
                'http_method',
                'url',
                'ip'
            ];
        };


        /**
         * @return WebProcessor
         */
        $dic['Logger.Processor.Web'] = function($dic) {
            $server = $dic['Logger.Environment'];
            $fields = $dic['Logger.Processor.Web.fields'];
            return new WebProcessor( $server, $fields );
        };


        /**
         * @return array
         */
        $dic['Logger.Processors'] = function($dic) {
            return [
                $dic['Logger.Processor.Web']
            ];
        };



        // ------------------------------------------
        // Monolog itself
        // ------------------------------------------



        /**
         * @return Monolog
         */
        $dic['Logger'] = function($dic) {
            $logname    = $dic['Logger.name'];
            $handlers   = $dic['Logger.Handlers'];
            $processors = $dic['Logger.Processors'];

            return new Monolog( $logname, $handlers, $processors );
        };



        // ------------------------------------------
        // Other helpers
        // ------------------------------------------

        $dic['Logger.LogRuntime.start'] = function($dic) {
            return $_SERVER['REQUEST_TIME_FLOAT'];
        };

        /**
         * @param  float $start_time
         * @return callable
         */
        $dic['Logger.LogRuntime'] = $dic->protect(function() use ($dic) {
            if (!$log_runtime = $dic['Logger.do_log_runtime']):
                return;
            endif;

            $logger = $dic['Logger'];
            $start_time = $dic['Logger.LogRuntime.start'];
            $runtime_ms = ((microtime("float") - $start_time) * 1000);

            $logger->info( "Finished", [ 'runtime' => $runtime_ms . "ms" ]);
        });


        // For statistic purposes
        if ($this->log_runtime):
            register_shutdown_function($dic->raw('Logger.LogRuntime'));
        endif;
    }
}

