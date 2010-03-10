<?php
/**
 * Option parser.
 *
 * Easily parse command line arguments in PHP. This parser has the same
 * interface as the python "optparse" package.
 *
 * Example usage:
 *   $parser = new OptionParser();
 *   $parser->add_option(array("-f", "--foo", "dest"=>"bar"));
 *   $values = $parser->parse_args($argv);
 */
require_once("utils.php");

define("NO_SUCH_ERROR", 1);

/**
 * Utility class for parsing arguments from the Command Line Interface
 *
 * @author Gabriel Filion
 */
class OptionParser {

    function OptionParser($settings=array()) {
        $this->_positional = array();
        $this->_options = array();

        $prog = basename($_SERVER["SCRIPT_FILENAME"]);
        $default_usage = "Usage: $prog [arguments ...]";
        $this->_usage = array_get($settings, "usage", $default_usage);

        $add_help_option = array_get($settings, "add_help_option", true);

        if ($add_help_option) {
            $this->add_option( array(
                "-h","--help",
                "dest" => "help",
                "callback" => "_display_help",
                "help" => "show this help message and exit"
            ) );
        }
    }

    /**
     * Add an option that the parser must recognize.
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function add_option($settings) {
        $this->_options[] = new Option($settings);
    }

    /**
     * Parse command line arguments
     *
     * Given an array of arguments, parse them and create an object containing
     * expected values and positional arguments.
     *
     * @author Gabriel Filion <gfilion@revolutionlinux.com>
     **/
    public function parse_args($argv, $values=Null){
        // Pop out the first argument, it is the command name
        array_shift($argv);

        if ( $values !== Null && ! is_array($values) ) {
            $msg = "Default values must be in an array";
            throw new Exception($msg);
        }

        if ($values === Null) {
            $values = $this->_get_default_values();
        }

        foreach ($argv as $arg){
            // Options should begin with a dash. All else is positional
            if (substr($arg,0,1) != "-"){
                $positional[] = $arg;
                continue;
            }

            $key_value = explode("=", $arg, 2);

            if (count($key_value) < 2) {
                $value = true;
            }
            else {
                $value = $key_value[1];
            }
            $this->_process_option($key_value[0], $value, $values);
        }

        return new Values($values, $positional);
    }

    /**
     * Process an option.
     *
     * Follow the option creation logic. First, verify existance of the
     * requested option. If the option is not found, display an error message
     * and exit. Let the option do the rest of the processing.
     *
     * @return void
     * @author Gabriel Filion
     **/
    private function _process_option($option_text, $value, &$values) {
        $option = $this->_find_option($option_text);

        if ($option === Null) {
            print($this->_usage."\n\n");
            print("Error: No such option: $option_text\n");
            exit(NO_SUCH_ERROR);
        }

        $option->process($value, $values, $this);
    }

    /**
     * Search for an option name in current options.
     *
     * @return Option object: when the option is found
     * @return Null: when the option is not found
     * @author Gabriel Filion
     **/
    private function _find_option($text) {
        $found = Null;

        foreach ($this->_options as $opt) {
            if ( in_array($text, $opt->argument_names) ) {
                $found = $opt;
                break;
            }
        }

        return $found;
    }

    /**
     * Get the list of default values.
     *
     * @return array
     * @author Gabriel Filion
     **/
    private function _get_default_values() {
        //TODO implement this
        return array();
    }
}

/**
 * Show a help message and exit.
 *
 * This is the callback method for the automatic help option. It displays a
 * help message with a list of available options and exits with code 0.
 *
 * @return void
 * @author Gabriel Filion
 **/
function _display_help($dummy, $parser) {
    // Print usage
    print( basename($_SERVER['SCRIPT_FILENAME']). $parser->usage. "\n\n" );

    // List all available options
    print("Options:\n");
    foreach ($parser->_options as $option) {
        print("  ". $option->_str() );
    }

    print "\n";
    exit(0);
}

/**
 * Object returned by parse_args.
 *
 * It contains two attributes: one for the options and one for the positional
 * arguments.
 **/
class Values {
    function Values($options, $positional) {
        $this->options = $options;
        $this->positional = $positional;
    }
}

/**
 * Class representing an option.
 *
 * The option parser uses this class to represent options that are added to it.
 **/
class Option {
    function Option($settings) {
        $argument_names = array();

        $i = 0;
        $longest_name = "";
        while ( $option_name = array_get($settings, "$i") ) {
            $argument_names[] = $option_name;

            // Get the name without leading dashes
            if ($option_name[1] == "-") {
                $name = substr($option_name, 2);
            }
            else {
                $name = substr($option_name, 1);
            }

            // Keep only the longest name for default dest
            if ( strlen($name) > strlen($longest_name) ) {
                $longest_name = $name;
            }

            $i++;
        }

        if ( empty($argument_names) ) {
            $msg = "An option must have at least one representation string";
            throw new Exception($msg);
        }

        $this->argument_names = $argument_names;

        $this->help_text = array_get($settings, "help", "");
        $this->callback = array_get($settings, "callback", Null);
        $this->dest = array_get($settings, "dest", $longest_name);
    }

    /**
     * Process the option.
     *
     * When used, the option must be processed. Verify value type. Call the
     * callback, if needed.
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function process($value, &$values, &$parser) {
        // No type checking as of now.

        if ($this->callback !== Null) {
            //FIXME determine what to pass on to callbacks
            $callback = $this->callback;
            $value = $callback($value, $parser);
        }

        $values[$this->dest] = $value;
    }

    /**
     * String representation of the option.
     *
     * Format a string with option name and description so that it can be used
     * for a help message.
     *
     * @return String: name and description of the option
     * @author Gabriel Filion
     **/
    public function _str() {
        $call_method = "";
        foreach ($this->argument_names as $name) {
            //FIXME this is not correct. dest must be shown only when needed.
            $call_method .= $name. " ". strtoupper($this->dest). " ";
        }

        return $call_method. " ". $this->help_text;
    }
}
?>
