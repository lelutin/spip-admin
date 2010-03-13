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

define("NO_SUCH_OPT_ERROR", 1);
define("WRONG_VALUE_COUNT_ERROR", 2);

/**
 * Utility class for parsing arguments from the Command Line Interface
 *
 * @author Gabriel Filion
 */
class OptionParser {

    function OptionParser($settings=array()) {
        $this->_positional = array();
        $this->_options = array();

        $vals = array( "prog" => $this->get_prog_name() );
        $default_usage = _translate("Usage: %(prog)s [arguments ...]", $vals);
        $this->_usage = array_get($settings, "usage", $default_usage);

        $add_help_option = array_get($settings, "add_help_option", true);

        if ($add_help_option) {
            $this->add_option( array(
                "-h","--help",
                "dest" => "help",
                "callback" => "_optparse_display_help",
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
        $new_option = new Option($settings);

        // Yell if an option text (e.g. --option) is already used.
        foreach ( $new_option->argument_names as $name ) {
            if ( $this->_find_option($name) !== Null ) {
                throw new DuplicateOptionException($name);
            }
        }

        $this->_options[] = $new_option;
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
        // Pop out the first argument, it is assumed to be the command name
        array_shift($argv);

        if ( $values !== Null && ! is_array($values) ) {
            $msg = _translate("Default values must be in an array");
            throw new InvalidArgumentException($msg);
        }

        if ($values === Null) {
            $values = $this->_get_default_values();
        }

        $rargs = $argv;

        $positional = array();
        while ( ! empty($rargs) ){
            $arg = array_shift($rargs);

            // Stop processing on a -- argument
            if ( $arg == "--" ) {
                // All remaining arguments are positional
                array_append($positional, $rargs);
                break;
            }

            // Options should begin with a dash. All else is positional
            if ( substr($arg, 0, 1) != "-" ) {
                $positional[] = $arg;
                continue;
            }

            if ( substr($arg, 0, 2) == "--" ) {
                $this->_process_long_option($arg, $values);
            }
            else {
                // values will be removed from $rargs during this process
                $this->_process_short_option($arg, $rargs, $values);
            }
        }

        return new Values($values, $positional);
    }

    /**
     * Process a long option.
     *
     * Long options are binary options, or they expect their value(s) to be
     * appended to them with = as a separator. If an option expects more than
     * one value, they should be a comma separated list.
     * Examples:
     *     program --enable-this
     *     program --option=value
     *     program --option=value1,value2
     *
     * @return void
     * @author Gabriel Filion
     **/
    private function _process_long_option($argument, &$values) {
        $key_value = explode("=", $argument, 2);
        $arg_text = $key_value[0];

        $option = $this->_find_option($arg_text);

        if ( count($key_value) > 1 ) {
            $opt_values = explode(",", $key_value[1]);
        }
        else {
            $opt_values = array();
        }

        if ( count($opt_values) < $option->nb_values ) {
            if ($option->nb_values == 1) {
                $vals = array("option" => $argument);
                $msg = _translate(
                    "%(option)s option takes a value.",
                    $vals
                );

                $this->error($msg, WRONG_VALUE_COUNT_ERROR);
            }
            else {
                $vals = array(
                    "option" => $argument,
                    "nbvals" => $option->nb_values
                );
                $msg = _translate(
                    "%(option)s option takes %(nbvals)s values.",
                    $vals
                );

                $this->error($msg, WRONG_VALUE_COUNT_ERROR);
            }

        }

        if (count($opt_values) < 1) {
            $value = true;
        }
        else {
            if ($option->nb_values < 1) {
                $vals = array("option" => $argument);
                $msg = _translate(
                    "%(option)s option does not take a value.",
                    $vals
                );

                $this->error($msg, WRONG_VALUE_COUNT_ERROR);
            }

            $value = $opt_values;
        }

        $this->_process_option($option, $arg_text, $value, $values);
    }

    /**
     * Process a short option.
     *
     * Short options are binary options, or they expect their value(s) to be in
     * the following arguments.
     * Examples:
     *     program -q
     *     program -d something
     *
     * @return void
     * @author Gabriel Filion
     **/
    private function _process_short_option($argument,
                                           &$rargs,
                                           &$values)
    {
        $option = $this->_find_option($argument);

        $nbvals = $option->nb_values;

        if ( $nbvals == 0 ) {
            $value = True;
        }
        else {
            $value = array();
        }

        if ( count($rargs) < $nbvals ) {
            if ( $nbvals == 1) {
                $vals = array("option" => $argument);
                $msg = _translate(
                    "%(option)s option takes an argument.",
                    $vals
                );
            }
            else {
                $vals = array("option" => $argument, "nbargs" => $nbvals);
                $msg = _translate(
                    "%(option)s option takes %(nbargs)s arguments.",
                    $vals
                );
            }

            $this->error($msg, WRONG_VALUE_COUNT_ERROR);
        }

        while ( $nbvals ) {
            $value[] = array_pop($rargs);
            $nbvals--;
        }

        // If only one value, set it directly as the value (not in an array)
        if ( $option->nb_values == 1 ) {
            $value = $value[0];
        }

        $this->_process_option($option, $argument, $value, $values);
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
    private function _process_option($option, $option_text, $value, &$values) {
        if ($option === Null) {
            $vals = array("option" => $option_text);
            $msg = _translate("Error: No such option: %(option)s", $vals);

            $this->error($msg, NO_SUCH_OPT_ERROR);
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

    /**
     * Exit program with an error message and code
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function error($text, $code) {
        $this->print_usage(STDERR);
        fprintf(STDERR, $this->get_prog_name(). ": error: ". $text. "\n");
        exit($code);
    }

    /**
     * Print usage
     *
     * Print usage message. Default output stream is stdout. To change it, pass
     * another stream as argument.
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function print_usage($stream=STDOUT) {
        fprintf($stream, $this->get_prog_name(). ": ". $this->_usage. "\n\n");
    }

    /**
     * Retrieve program name
     *
     * @return String
     * @author Gabriel Filion
     **/
    public function get_prog_name() {
        return basename($_SERVER['SCRIPT_FILENAME']);
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
function _optparse_display_help($dummy, $parser) {
    // Print usage
    $parser->print_usage();

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
            $msg = _translate(
                "An option must have at least one string representation"
            );
            throw new InvalidArgumentException($msg);
        }

        $this->argument_names = $argument_names;

        $this->help_text = _translate( array_get($settings, "help", "") );
        $this->callback = array_get($settings, "callback", Null);
        $this->dest = array_get($settings, "dest", $longest_name);

        $this->nb_values = array_get($settings, "nargs", 1);
        if ($this->nb_values < 0) {
            $msg = _translate("nargs setting to Option cannot be negative");
            throw new InvalidArgumentException($msg);
        }
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
            $dest = _translate($this->dest);
            $call_method .= $name. " ". strtoupper($dest). " ";
        }

        return $call_method. " ". _translate($this->help_text);
    }
}

/**
 * Exception on duplicate options
 *
 * Exception raised when an option added tries to use a string representation
 * (e.g. "--option") that is already used by a previously added option.
 **/
class DuplicateOptionException extends Exception {
    function DuplicateOptionException($name) {
        $msg = _translate("Duplicate definition of option \"$name\"");
        parent::__construct($msg);
    }
}

?>
