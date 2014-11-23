<?php
/**
 * Recursively show or modify the EOL of text-file(s).
 * 
 * As we all know, there are three different EOL in Windows/Linux/Mac: "\r\n",
 * "\n" and "\r". Given a file or directory, this script can list all the files
 * with there EOL recursively, or change all the EOL of these files to 
 * specified one.
 * 
 * USAGE:
 *  php [OPTION]
 *  
 *  Common option:
 *    --suffix  specify which files to show or modify.
 *              common programming language suffixes, and some common 
 *              text-file suffixes(like txt) have been given, you can change them if it
 *              cann't satisfy you.
 *              default vlaues: cpp/c/hpp/h/java/jsp/php/py/js/css/html/txt
 *    --path    dir or file.
 *              default value is current dir, can be omitted.
 *  Show option:
 *    -s        default option, can be omitted.
 *  Modify option:
 *    -m        change EOL.
 *    --from    source EOL, change only if the file's EOL is this. 
 *              if notprovided, all file will be
 *              value may be "windows", "linux" or "mac", case-insensitive.
 *    --to      dest EOL, change the EOL to this, default value is PHP_EOL, 
 *              which is machine-dependent
 * 
 * Example:
 *  1. show EOL of all files in current path.
 *       php convert_end_of_line.php
 *       ATTN: '-s' has been omitted because it is default option.
 *  2. change all the windows files in 'dir2' to Linux's EOL
 *       php convert_end_of_line.php --from=windows --to=linux dir2 
 *  3. change all the 'java and cpp source file' in 'dir3' to current system's EOL
 *       php convert_end_of_line.php --suffix='java cpp c h hpp' dir3
 * 
 * @author LingBin <lingbinlb@gmail.com>
 * @date 2014/11/22 11:52:01 
 **/

ini_set('memory_limit','1024M');
ini_set('date.timezone','Asia/Shanghai');
ini_set("auto_detect_line_endings", true); // in order to read mac-text-file correctly

$g_default_suffixes = array(
    'cpp', 'c', 'hpp', 'h', 'java', 'jsp',
    'php', 'py', 'js', 'css', 'html', 'txt',
);


$option = new Option();
$option->getOption();
if (!$option->checkOption()) {
    Logger::log('cmd option is illegal, please check.');
    exit(-1);
}
//Logger::log('option: %s', var_export($option, true));

if ($option->mode == ProcessMode::SHOW) {
    $eolTree = Util::getPathEOL($option->path, $option->suffixes);
    Logger::log("result: \n%s", Util::printFileTree($eolTree));
} else if ($option->mode == ProcessMode::MODIFY) {
    Util::convertPathEOL($option->path, $option->suffixes, $option->from, $option->to);
}
Logger::log("-----------*****--****--------");


class Logger{
    public static function log(){
        $parameters = func_get_args();
    
        $traces = debug_backtrace();
        $traceCount = count($traces);
        $result = array();
        for ($i = $traceCount - 1; $i >= 0; --$i) {
            if (isset($traces[$i]['file'], $traces[$i]['line'], $traces[$i]['class'])
                && $traces[$i]['class'] == 'Logger') {
                // when php-version >5.3.0, should use get_called_class() instead of hard-coding as 'Logger'
                $parameters[0] = '[' . $traces[$i]['line'] . '] ' . $parameters[0];
                $parameters[0] = '[' . basename($traces[$i]['file']) . ']' . $parameters[0];
                break;
            }
        }
        $parameters[0] .= "\n";
        array_unshift($parameters, STDOUT);
        $flag = call_user_func_array(fprintf, $parameters);
        if(flag === false){
            exit('[' . __FILE__ . '][' . __LINE__ . ']something is wrong when invoke call_user_func_array in trace. ' . "\n");
        }
    }
}

class Util{
    public static function strEndWith($str, $subStr){
        return substr($str, -(strlen($subStr))) == $subStr;
    }
    
    public static function getPathEOL($path, $allow_suffixes){
        if (is_file($path)) {
            return array($path => self::getFileEOL($path));
        }

        $dh = opendir($path);
        if ($dh == false) {
            Logger::log("fail to open dir: %s", $path);
            return false;
        }
    
        $result = array();
        while (($fileName = readdir($dh)) !== false) {
            if ($fileName == '.' || $fileName == '..') {
                continue;
            }
            if ($fileName[0] == '.') { // ignore hidden files like '.git' or '.svn'
                continue;
            }
            $subFile = $path . DIRECTORY_SEPARATOR . $fileName;
            if (is_dir($subFile)) {
                Logger::log("it is dir: %s", $subFile);
                $result[$fileName . '(dir)'] = self::getPathEOL($subFile, $allow_suffixes);
            } else if (is_file($subFile)) {
                $subFileInfo = pathinfo($subFile);
                // Logger::log("file extentsion: %s", $subFileInfo['extension']);
                if (!in_array($subFileInfo['extension'], $allow_suffixes)) {
                    Logger::log("not_supported extentsion: %s", $subFileInfo['extension']);
                    continue;
                }
                $result[$fileName] = self::getFileEOL($subFile);
            }
        }
        return $result;
    }
    
    /**
     * ATTN: remember to set option, or 'file()' fuction can not split lines correctly.
     *      ini_set("auto_detect_line_endings", true);
     * 
     * @return int|boolean  file's EOL type. if the file is empty, return 0.
     */
    public static function getFileEOL($path){
        if (!is_file($path)) {
            Logger::log('it is not file: %s', $path);
            return false;
        }
        $file_eol_type = 0;
        $lines = file($path);
        foreach ($lines as $num => $line) {
            if (self::strEndWith($line, SystemEOL::WINDOWS)) {
                $file_eol_type |= FileEOLType::WINDOWS; 
            } else if (self::strEndWith($line, SystemEOL::LINUX)) {
                $file_eol_type |= FileEOLType::LINUX; 
            } else if (self::strEndWith($line, SystemEOL::MAC)) {
                $file_eol_type |= FileEOLType::MAC; 
            }
        }
        return $file_eol_type;
    }
    
    // format like below:
    //  |-- dirA
    //      |-- fileA[linux]
    //      |-- dirB
    //          |-- fileB[windows]
    //          |-- fileC[mac]
    public static function printFileTree($tree, $level = 0){
        if(!is_array($tree)) {
            Logger::log("Invalid parameter: not a array, it is: %s", var_export($tree, true));
        }
        $resultStr = '';
        foreach ($tree as $path => $node) {
            $resultStr .= self::getIndentionString($level);
            $resultStr .= '|---' . $path;
            if(is_array($node)){ // dir
                $resultStr .= PHP_EOL;
                $resultStr .= self::printFileTree($node, $level + 1);
            } else { // file
                $resultStr .= '[' . FileEOLType::$type_str[$node] .']' . PHP_EOL;
            }
        }
        return $resultStr;
    }
    public static function getIndentionString($level){
        $resultStr = '';
        for ($i = 0; $i < $level; ++$i) {
            $resultStr .= '    ';
        }
        return $resultStr;
    }
    
    public static function convertFileEOL($file_path, $from_eol, $to_eol){
        if (!is_file($file_path)) {
            Logger::log('it is not file: [%s]', $file_path);
            return false;
        }
        
        $file_eof_type = self::getFileEOL($file_path);
        if ($file_eof_type === false) {
            return false;
        }
        // Logger::log('file_eol: [%s]; from_eol: [%s]; to_eol: [%s]', $file_eof_type, $from_eol, $to_eol);
        
        if ($file_eof_type === $to_eol) {
            Logger::log('same with to_eol, no need to convert.');
            return true;
        }
        
        $fileContent = file_get_contents($file_path);
        if ($fileContent === false) {
            Logger::log('Failed to get file content. file_path: ', $file_path);
            return false;
        }
        
        if (($file_eof_type & $from_eol) == 0) {// & FileEOLType::WINDOWS) {
            Logger::log("file's eol is not in from_eol. file_path: [%s]; file's eol: [%s]; from_eol: [%s]",
                        $file_path, $file_eof_type, $from_eol);
            return true;
        }
        
        // ATTN: must check windows first, otherwise may get wrong result.
        // eg. a file have both "\r\n" and "\r", and need to convert to "\n":
        //     if we convert "\r"(mac) first, "\r\n" will be changed to "\n\n", 
        //     which is wrong result.
        if ($file_eof_type & FileEOLType::WINDOWS) {
            $fileContent = str_replace(
                            FileEOLType::$EOL_STR[FileEOLType::WINDOWS],
                            FileEOLType::$EOL_STR[$to_eol],
                            $fileContent);
        }
        if ($file_eof_type & FileEOLType::LINUX) {
            $fileContent = str_replace(
                            FileEOLType::$EOL_STR[FileEOLType::LINUX],
                            FileEOLType::$EOL_STR[$to_eol],
                            $fileContent);
        }
        if ($file_eof_type & FileEOLType::MAC) {
            $fileContent = str_replace(
                            FileEOLType::$EOL_STR[FileEOLType::MAC],
                            FileEOLType::$EOL_STR[$to_eol],
                            $fileContent);
        }
        Logger::log('convert file(%s) from [%s] to [%s]', $file_path, $from_eol, $to_eol);
    
        $flag = file_put_contents($file_path, $fileContent);
        if ($flag === false) {
            Logger::log('Failed to write file: %s', $file_path);
            return false;
        }
        return true;
    }
    
    public static function convertPathEOL($path, $allow_suffixes, $from_eol, $to_eol){
        if (!is_dir($path)) {
            Logger::log("it is not a dir: [%s]", $path);
            return self::convertFileEOL($path, $from_eol, $to_eol);
        }
    
        $dh = opendir($path);
        if ($dh == false) {
            Logger::log("fail to open dir: %s", $path);
            return false;
        }
    
        while (($fileName = readdir($dh)) !== false) {
            if ($fileName == '.' || $fileName == '..') {
                continue;
            }
            if ($fileName[0] == '.') { // remove hidden files, eg. '.git'
                continue;
            }
            $subFile = $path . DIRECTORY_SEPARATOR . $fileName;
            if(is_dir($subFile)) {
                Logger::log("sub dir: $s", $subFile);
                self::convertPathEOL($subFile, $allow_suffixes, $from_eol, $to_eol);
            } else if (is_file($subFile)) {
                $subFileInfo = pathinfo($subFile);
                // Logger::log("path extentsion: %s", $subFileInfo['extension']);
                if (!in_array($subFileInfo['extension'], $allow_suffixes)) {
                    continue;
                }
                $flag = self::convertFileEOL($subFile, $from_eol, $to_eol);
                //$result[$fileName] = getFileEncoding($subFile);
            }
        }
        return $result;
    }
}

class ProcessMode {
    const SHOW = 1;
    const MODIFY = 2;
}

class SystemEOL {
    const WINDOWS = "\r\n";
    const LINUX = "\n";
    const MAC = "\r";
    const ALL = "";
//     static $EOL = array (
//         'windows' => self::WINDOWS,
//         'linux' => self::LINUX,
//         'mac' => self::MAC,
//     );
    static $SYS_EOL_TYPE = array(
        self::WINDOWS => FileEOLType::WINDOWS,
        self::LINUX => FileEOLType::LINUX,
        self::MAC => FileEOLType::MAC,
    );
}

class FileEOLType {
    const INVALID = 0;
    const WINDOWS = 1;
    const LINUX = 2;
    const MAC = 4;
    const ALL = 7;
    static $type_str = array (
        self::INVALID => 'invalid',
        self::WINDOWS => 'windows',
        self::LINUX => 'linux',
        3 => 'windows_linux',
        self::MAC => 'mac',
        5 => 'windows_mac',
        6 => 'linux_mac',
        self::ALL => 'windows_linux_mac',
    );
    static $EOL = array (
        'windows' => self::WINDOWS,
        'linux' => self::LINUX,
        'mac' => self::MAC,
    );
    static $EOL_STR = array(
        self::WINDOWS => SystemEOL::WINDOWS,
        self::LINUX => SystemEOL::LINUX,
        self::MAC => SystemEOL::MAC,
    );
}


class Option {
    public $suffixes;
    public $mode;
    public $path;
    public $from;
    public $to;
    
    public function __construct() {
        $this->suffixes = $GLOBALS['g_default_suffixes'];
        $this->mode = ProcessMode::SHOW;
        $this->path = dirname(__FILE__);
        $this->from = FileEOLType::ALL;
        $this->to = SystemEOL::$SYS_EOL_TYPE[PHP_EOL];
    }

    public function getOption() {
        $shortopts = 'sm';
        $longopts = array(
            'suffix::',
            'path::',
            'from::',
            'to::',
        );
        $cmdOptions = getopt($shortopts, $longopts);
        Logger::log('cmd_option: %s', var_export($cmdOptions, true));
        if ($cmdOptions === false) {
            exit("failed to parse cmd option: " . var_export($cmdOptions, true));
        }
        
        if (isset($cmdOptions['m']) && isset($cmdOptions['s'])) {
            exit('can not choose both mode. eithor show or modify.');
        } else {
            if (isset($cmdOptions['m'])) {
                $this->mode = ProcessMode::MODIFY;
            }
        }
        
        if (isset($cmdOptions['suffix'])) {
            $this->suffixes = array();
            if (is_array($cmdOptions['suffix'])) {
                foreach ($cmdOptions['suffix'] as $suffixes){
                    $temp_array = explode(' ', $suffixes);
                    $this->suffixes = array_merge($this->suffixes, $temp_array);
                } 
            } else {
                $this->suffixes = explode(' ', $cmdOptions['suffix']);
            }
        }
        
        if (isset($cmdOptions['path'])) {
            $this->path = realpath($cmdOptions['path']);
        }
        
        if (isset($cmdOptions['from'])) {
            $from_str = strtolower($cmdOptions['from']);
            if (isset(FileEOLType::$EOL[$from_str])) {
                $this->from = FileEOLType::$EOL[$from_str];
            } else {
                exit("'from' option is innvalid, should be one of "
                    . "'linux'/'windows'/'mac'. "
                    . "current value: " 
                    . $from_str);
            }
        }
        
        if (isset($cmdOptions['to'])) {
            $to_str = strtolower($cmdOptions['to']);
            if (isset(FileEOLType::$EOL[$to_str])) {
                $this->to = FileEOLType::$EOL[$to_str];
            } else {
                Logger::log("'to' option is innvalid, should be one of "
                    . "'linux'/'windows'/'mac'. " 
                    . "current value: "
                    . $cmdOptions['to']);
                exit(-1);
            }
        }
    }

    /**
     * @return bool
     */
    public function checkOption() {
        Logger::log('debug option: %s', $this->toString());
        if (!file_exists($this->path)) {
            exit('path is not exist. path: ' . $this->path);
        }
        return true;
    }
    
    public function toString() {
        $str = 'suffixes: [' . implode(',', $this->suffixes). '] ';
        $str .= 'mode: ' . $this->mode . '; ';
        $str .= 'path: ' . $this->path . '; ';
        $str .= 'from: ' . $this->from . '; ';
        $str .= 'to: ' . $this->to . '; ';
        return $str;
    }
}

/* vim: set expandtab ts=4 sw=4 sts=4 tw=100: */
?>
