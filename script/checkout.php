<?php
/**
 * checkout.php -- htmLawed - Git Edition
 *
 * copyright (c) 2015 hakre <http://hakre.wordpress.com>
 *
 * checkout htmLawed zip-packages from sourceforge to git working dir
 *
 * usage: php -f checkout.php -- <filename>
 * download file from sourceforge standard URL. Basename only, e.g. htmLawed_1.1.19_19Jan2015.zip
 *
 *   this file is not part of the htmlLawed library and ships under compatible APGL-3.0+ license. It is not part
 * of git(hub) zip package distibution (via ../.gitattributes), which is --prefer-dist with composer to keep the
 * original licensing. Otherwise you only need to remove this file (and re-add it later if you need to upgrate from
 * sourceforge)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

function usage()
{
    echo <<<TEXT
usage: php -f checkout.php -- <filename>

    download file from sourceforge standard URL. Basename only, e.g. 'htmLawed_1.1.19_19Jan2015.zip'.

TEXT;

}


function main($self, $filename = null)
{
    $commitMessage = "no downloads\n";

    $status = 0;

    if (!$self || !$filename || $filename == '-h' || $filename == '--help') {
        usage();
    } else {
        try {
            package_import($filename, $commitMessage);
        } catch (Exception $e) {
            fprintf(STDERR, "error: %s\n", $e->getMessage());
            usage();
            $status = 129;
        }
    }

    echo "\n";
    exit($status);
}


/**
 * checkout to a zop package on sourceforge
 */
class ZipArchiveIterator implements IteratorAggregate
{
    /**
     * @var ZipArchive
     */
    private $zipArchive;

    /**
     * @param ZipArchive $zipArchive
     */
    function __construct(ZipArchive $zipArchive)
    {
        $this->zipArchive = $zipArchive;
    }

    public function getIterator()
    {
        $files = array();
        for ($i = 0; $i < $this->zipArchive->numFiles; $i++) {
            $name = $this->zipArchive->getNameIndex($i);
            // filter out unwanted files
            $base = basename($name);
            if (in_array($base, array('.DS_Store'))) {
                continue;
            }
            $files[] = $name;
        }

        return new ArrayIterator($files);
    }
}

class ZipExtraction implements IteratorAggregate
{
    /**
     * @var string
     */
    private $destination;

    /**
     * @var ZipArchive
     */
    private $zip;

    /**
     * @param string $filename of zip-archive
     * @param string $destination directory to extract to
     * @return ZipExtraction
     */
    public static function create($filename, $destination)
    {
        // open zip-file
        $zip    = new ZipArchive();
        $result = $zip->open($filename);
        if (true !== $result) {
            throw new InvalidArgumentException(sprintf('unable to open zip-archive %s', $filename));
        }

        return new self($zip, $destination);
    }

    function __construct(ZipArchive $zip, $destination)
    {
        $this->zip         = $zip;
        $this->destination = $destination;
    }

    /**
     * @param string $name of file to extract from zip-archive
     * @return int|false
     */
    public function extract($name)
    {
        $destination = $this->destination($name);

        if ($destination->isDir()) {
            return 0;
        }

        return file_put_contents($destination, $this->zip->getStream($name));
    }

    /**
     * @param string $name
     * @return SplFileInfo
     */
    public function destination($name)
    {
        $file_name = rtrim($this->destination, '/') . '/' . $this->destinationName($name);

        return new SplFileInfo($file_name);
    }

    /**
     * @param $name
     * @return string
     */
    public function destinationName($name)
    {
        // optional filter out basedirectory in zip
        if (substr($name, 0, 9) === 'htmLawed/') {
            $name = substr($name, 9);
        }

        return ltrim($name, '/');
    }

    /**
     * @return ZipArchiveIterator
     */
    public function getIterator()
    {
        return new ZipArchiveIterator($this->zip);
    }
}

class SourceforgeDownload
{
    const BASE_URL_MASK = 'http://sourceforge.net/projects/%s/files/';

    private $project;
    private $root;
    private $directoryCache;
    private $beforeDownload;

    function __construct($project, $root)
    {
        $this->project = (string) $project;
        $this->root    = (string) $root;
    }

    /**
     * @return SourceforgeDownload
     */
    public static function create($beforeDownload = null)
    {
        $project              = 'htmLawed';
        $root                 = sprintf(self::BASE_URL_MASK, strtolower($project));
        $self                 = new self($project, $root);
        $self->beforeDownload = $beforeDownload;

        return $self;
    }

    /**
     * @param $filename
     * @return string download URL from sourceforge.net
     */
    public function getUrl($filename)
    {
        return $this->root . trim($filename, '/') . '/download';
    }

    public function getListing($directory = '')
    {
        $directory = trim($directory, '/');
        if (isset($this->directoryCache[$directory])) {
            $listing = $this->directoryCache[$directory];
        } else {
            $listing = array();

            $html = DOMBLAZE::create(rtrim($this->root, '/') . '/' . $directory . '/', $this->beforeDownload);

            $files = $html("//table[@id='files_list']//tr[starts-with(@class, 'file ') or starts-with(@class, 'folder ')]");
            foreach ($files as $row) {
                $type      = trim($row('string(./@class)'));
                $href      = trim($row("string(./th/a/@href)"));
                $listing[] = array(
                    'type' => $type,
                    'name' => trim($row("string(./th/a)")),
                    'url'  => '/' . ltrim($directory . '/', '/') . basename($type === 'file' ? dirname($href) : $href),
                    'date' => trim($row("string(./td[1]/abbr/@title)")),
                );
            }

            $this->directoryCache[$directory] = $listing;
        }

        // recurse subdirectories
        $subFolder = $this->filterType($listing, 'folder');

        foreach ($subFolder as $folder) {
            foreach ($this->getListing($directory . '/' . $folder['url']) as $item) {
                $item['dir'] = $folder['name'];
                $listing[]   = $item;
            }
        }

        return $listing;
    }

    /**
     * @param $filename
     * @return array|null
     */
    public function getFileByName($filename)
    {
        $array = $this->filterType($this->getListing(), 'file');
        if (false !== strpos($filename, '/')) {
            $bare = $this->filterField($array, 'url', '/' . ltrim($filename, '/'));
            if (!$bare) {
                $this->filterField($array, 'url', '/' . ltrim(urlencode($filename), '/'));
            }
            $array = $bare;
        } else {
            $array = $this->filterField($array, 'name', $filename);
        }

        return reset($array);
    }

    public function getFileByIndex($index)
    {
        $index = (int) max(0, $index);
        $files = $this->filterType($this->getListing(), 'file');
        if (isset($files[$index])) {
            return $files[$index];
        }

        return null;
    }

    /**
     * @return array
     */
    public function getFiles()
    {
        return $this->filterType($this->getListing(), 'file');
    }

    /**
     * @param array $listing
     * @param string $type
     * @return array
     */
    private function filterType($listing, $type)
    {
        $filtered = $this->filterField($listing, 'type', $type);

        return $filtered;
    }

    /**
     * @param $listing
     * @param $value
     * @param $field
     * @return array
     */
    private function filterField($listing, $field, $value)
    {
        $filtered = array();
        foreach ($listing as $item) {
            if ($item[$field] !== $value) {
                continue;
            }
            $filtered[] = $item;
        }

        return $filtered;
    }
}

/**
 * Class DOMBLAZE
 *
 * DOMBLAZE is FluentDOM for the poor.
 */
class DOMBLAZE extends DOMElement
{
    /**
     * @param $mixed
     * @param null $beforeDownload
     * @return DOMBLAZE document element
     */
    public static function create($mixed, $beforeDownload = null)
    {
        if ($mixed instanceof DOMNode) {
            $node = $mixed;
        } else {
            $node = self::createDoc($mixed, $beforeDownload);
        }

        return self::blaze($node);
    }

    /**
     * @param DOMNode $node
     * @return DOMBLAZE document element
     */
    public static function blaze(DOMNode $node)
    {
        $doc = ($node instanceof DOMDocument) ? $node : $node->ownerDocument;

        if (!$doc) {
            throw new InvalidArgumentException('DOMBLAZE needs a document');
        }

        /* DOMBLAZE */
        $doc->registerNodeClass("DOMElement", __CLASS__);

        return $doc->documentElement;
    }

    /**
     * @param string $resource
     * @param callback $beforeDownload (optional)
     * @return DOMDocument
     */
    public static function createDoc($resource, $beforeDownload = null)
    {
        $doc = new DOMDocument();

        if (!strlen($resource)) {
            return $doc;
        }

        $doc->recover;
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput       = true;
        $saved                   = libxml_use_internal_errors(true);
        if ('<' === $resource[0]) {
            $result = $doc->loadHTML($resource);
        } else {
            $cacheFile = __DIR__ . '/var/cache/' . md5($resource) . '.html';
            $cacheAge  = null;
            if (is_readable($cacheFile)) {
                $cacheAge = max(1, $_SERVER['REQUEST_TIME'] - filemtime($cacheFile));
            }

            if ($cacheAge && $cacheAge < 3600) {
                $result = $doc->loadHTMLFile($cacheFile);
            } else {
                if (is_callable($beforeDownload)) {
                    call_user_func($beforeDownload, $resource);
                }
                if ($result = $doc->loadHTMLFile($resource)) {
                    $doc->saveHTMLFile($cacheFile);
                };
            }
        }
        libxml_use_internal_errors($saved);

        if (!$result) {
            throw new InvalidArgumentException('Could not create from %s', var_export(substr($resource, 64), true));
        }

        return $doc;
    }

    public function __invoke($expression)
    {
        return $this->xpath($expression);
    }

    function xpath($expression)
    {
        $result = new DOMXPath($this->ownerDocument);
        $result = $result->evaluate($expression, $this);

        return ($result instanceof DOMNodeList) ? new IteratorIterator($result) : $result;
    }
}

function file_version_extract($downloadUrl)
{
    $pattern = '~\bhtmLawed_(?P<version>\d+.\d+.\d+)_(?P<date>\d+[A-Z][a-z]{2}20\d\d)\.zip\b~';

    $result = preg_match("$pattern", $downloadUrl, $matches);

    if (false === $result) {
        throw new UnexpectedValueException('Regular expression to extract version and date failed.');
    }

    if (!$result) {
        return false;
    }

    $result = $matches;

    $date = $result['date'];
    if (!preg_match('~(\d+)([A-Za-z]+)(\d+)~', $date, $matches)) {
        throw new UnexpectedValueException('Sub-expression for date not matched.');
    }
    array_shift($matches);
    $matches[2]          = $matches[2] - 2000;
    $result['nice_date'] = vsprintf('%d %s %02d', $matches);

    return $result;
}

function package_import($filename)
{
    $downloader = SourceforgeDownload::create(function () {
        echo "obtaining files from sourceforge...\n";
    });

    if (ltrim($filename, '0') === (string) (int) $filename) {
        $fileNumber = (int) $filename;
        if (!$fileInfo = $downloader->getFileByIndex($fileNumber - 1)) {
            exit_sourceforge_files($downloader, $filename);
        }
        $filename = $fileInfo['url'];
        printf("file number %d is %s\n", $fileNumber, var_export(ltrim($filename, '/'), true));
    }

    $downloadUrl = $downloader->getUrl($filename);
    $tempStub    = 'var/zip/' . basename(dirname($downloadUrl));
    $temp        = __DIR__ . '/' . $tempStub;

    if (!is_readable($temp)) {
        if (!$fileInfo = $downloader->getFileByName($filename)) {
            exit_sourceforge_files($downloader, $filename);
        };
        echo "downloading from: ", $downloadUrl, "\n";
        echo "downloading to: ", $tempStub, "\n";
        if (!copy($downloadUrl, $temp)) {
            throw new RuntimeException('failed to download from server');
        }
        file_put_contents($temp . '.json', json_encode($fileInfo, defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT : 0));
    } else {
        echo "already downloaded to  ", $tempStub, ", remove the file if you want to download again.\n";
        $fileInfo = json_decode(file_get_contents($temp . '.json'), true);
    }

    $commitMessage = null;
    $result        = file_version_extract($downloadUrl);
    $packagistVersion = null;
    if ($result) {
        $commitMessage = sprintf('updated to v%s, %s', $result['version'], $result['nice_date']);
        $packagistVersion = $result['version'];
        process_composer_json($packagistVersion);
    }

    $extraction = ZipExtraction::create($temp, __DIR__ . '/../');

    $addedFiles = array();

    foreach ($extraction as $name) {

        $destination = $extraction->destination($name);
        if ($destination->isFile()) {
            $mode = 'overwrite';
        } elseif ($destination->isDir()) {
            $mode = 'directory';
        } else {
            $mode = 'create';
        }

        if ($mode !== 'directory') {
            printf("unzip %s (%s): ", $name, $mode);
            $bytes = $extraction->extract($name);
            printf("%s bytes\n", number_format($bytes));

            if ($bytes) {
                $addedFiles[] = array($destination, $extraction->destinationName($name));
                shell_exec(sprintf('git add %s', escapeshellarg($destination)));
            }
        }
    }

    if ($commitMessage) {
        echo "nice commit message suggestion:\n", $commitMessage, "\n";
    } else {
        $commitMessage = sprintf('take %s from sourceforge', basename($filename));
        echo "fallback commit message suggestion:\n", $commitMessage, "\n";
    }

    $commitMessage .= create_message($fileInfo, $temp);
    $commitMessage .= create_listing($addedFiles);

    echo "command:\n";
    $commit = ShellExec::create()->setCommand('git')->addRaw('commit')
        ->addSwitch('--author', 'Santosh Patnaik <santosh.patnaik@roswellpark.org>')
        ->addSwitch('--date', $fileInfo['date'])
        ->addSwitch('-F')->addViaTmp($commitMessage);

    echo $commit, "\n";

    $commit->execute();

    if ($packagistVersion) {
        shell_exec(sprintf('git tag -f %s', escapeshellarg($packagistVersion)));
    }
}

class ShellExec
{
    private $cmd;

    private $args;

    private $tmpHandles;

    /**
     * @return ShellExec
     */
    public static function create()
    {
        $self = new self();

        return $self;
    }

    /**
     * @param string $cmd
     * @return $this
     */
    public function setCommand($cmd)
    {
        $this->cmd = (string) $cmd;

        return $this;
    }

    /**
     * @param $switch
     * @param null $arg
     * @return $this
     */
    public function addSwitch($switch, $arg = null)
    {
        $this->addRaw($switch);
        if (null !== $arg) {
            $this->addArg($arg);
        }

        return $this;
    }

    /**
     * creates tempfile on the fly containing data and provides the filename of the temporary file as parameter
     *
     * @param string $data
     * @return $this
     */
    public function addViaTmp($data)
    {
        $this->args[] = array($data, 2);

        return $this;
    }

    public function addRaw($raw)
    {
        $this->args[] = array($raw, 0);

        return $this;
    }

    /**
     * @param $arg
     * @return $this
     */
    public function addArg($arg)
    {
        $this->args[] = array($arg, 1);

        return $this;
    }

    /**
     * @return string
     */
    public function execute()
    {
        $command = $this->getCommand();

        return shell_exec($command);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getCommand();
    }

    /**
     * @return string
     */
    private function getCommand()
    {
        $command = escapeshellcmd($this->cmd);
        foreach ($this->args as $arg) {
            list($value, $mode) = $arg;
            switch ($mode) {
                case 0:
                    $command .= ' ' . $value;
                    break;

                case 1:
                    $command .= ' ' . escapeshellarg($value);
                    break;

                case 2:
                    $command .= ' ' . escapeshellarg($this->tmpFile($value));
                    break;

                default:
                    throw new RuntimeException(sprintf('unknown mode %s for %s', var_export($mode, true), var_export($value, true)));
            }
        }

        return $command;
    }

    /**
     * @param $buffer
     * @return mixed
     */
    private function tmpFile($buffer)
    {
        $temp = tmpfile();
        if (!$temp) {
            throw new RuntimeException('unable to create temporary file');
        }

        $this->tmpHandles[] = $temp;

        fwrite($temp, $buffer);
        fflush($temp);
        $metaData = stream_get_meta_data($temp);
        $filename = $metaData["uri"];

        return $filename;
    }
}

function process_composer_json($newVersion)
{
    if (!preg_match('~^\d+\.\d+\.\d+$~', $newVersion)) {
        throw new InvalidArgumentException(sprintf('Need composer compatible version string, %s given', var_export($newVersion)));
    }

    $composerFile = __DIR__ . '/../composer.json';
    $buffer       = file_get_contents($composerFile);

    $result = preg_replace('~^(\s+"version"\s*:\s*")([^"]+)(",)$~m', "\${1}$newVersion\\3", $buffer, 1, $count);

    if ($count !== 1) {
        throw new RuntimeException('failed to locate version in composer.json');
    }

    $saved = file_put_contents($composerFile, $result);

    if (!$saved) {
        throw new RuntimeException('failed to update composer.json with new version');
    }

    shell_exec(sprintf('git add -- %s', escapeshellarg($composerFile)));
}

/**
 * @param array $fileInfo sourceforge
 * @param string $temp zip-archive on disk
 * @return string
 */
function create_message($fileInfo, $temp)
{
    return sprintf("\n\nfile: %s\ndate: %s\n\nSHA256:%s\nSHA1:%s\nMD5:%s\n\nListing:\n--------\n",
        urldecode($fileInfo['url']), $fileInfo['date'], hash_file('sha256', $temp), sha1_file($temp), md5_file($temp)
    );
}

function create_listing(array $files)
{
    $buffer = '';

    $maxLen = 0;
    foreach ($files as $file) {
        $maxLen = max($maxLen, strlen($file[1]));
    }

    foreach ($files as $index => $file) {
        $buffer .= sprintf("%' 4s %' -{$maxLen}s MD5:%s\n", sprintf("%d.", $index + 1), $file[1], md5_file($file[0]));
    }

    return $buffer;
}

/**
 * @param SourceforgeDownload $downloader
 * @param string $filename
 */
function exit_sourceforge_files(SourceforgeDownload $downloader, $filename)
{
    fprintf(STDERR, "error: unable to find file %s on sourceforge\n", var_export($filename, 1));
    echo "the following files are available:\n";
    $count = 0;
    foreach ($downloader->getFiles() as $file) {
        $count++;
        printf("  #%02d  %' -34s    %s\n", $count, $file['name'], $file['date']);
    }
    exit(4);
}

call_user_func_array('main', $argv);
