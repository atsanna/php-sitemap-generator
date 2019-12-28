<?php

namespace Icamys\SitemapGenerator;

use BadMethodCallException;
use DateTime;
use DOMDocument;
use InvalidArgumentException;
use LengthException;
use RuntimeException;
use SimpleXMLElement;
use SplFixedArray;

/**
 * Class SitemapGenerator
 * @package Icamys\SitemapGenerator
 */
class SitemapGenerator
{
    /**
     * Max size of a sitemap according to spec.
     * @see https://www.sitemaps.org/protocol.html
     */
    const MAX_FILE_SIZE = 52428800;

    /**
     * Max number of urls per sitemap according to spec.
     * @see https://www.sitemaps.org/protocol.html
     */
    const MAX_URLS_PER_SITEMAP = 50000;

    /**
     * Max number of sitemaps per index file according to spec.
     * @see http://www.sitemaps.org/protocol.html
     */
    const MAX_SITEMAPS_PER_INDEX = 50000;

    const URL_PARAM_LOC = 0;
    const URL_PARAM_LASTMOD = 1;
    const URL_PARAM_CHANGEFREQ = 2;
    const URL_PARAM_PRIORITY = 3;
    const URL_PARAM_ALTERNATES = 4;

    /**
     * Robots file name
     * @var string
     * @access public
     */
    private $robotsFileName = "robots.txt";
    /**
     * Name of sitemap file
     * @var string
     * @access public
     */
    private $sitemapFileName = "sitemap.xml";
    /**
     * Name of sitemap index file
     * @var string
     * @access public
     */
    private $sitemapIndexFileName = "sitemap-index.xml";
    /**
     * Quantity of URLs per single sitemap file.
     * If Your links are very long, sitemap file can be bigger than 10MB,
     * in this case use smaller value.
     * @var int
     * @access public
     */
    private $maxURLsPerSitemap = self::MAX_URLS_PER_SITEMAP;
    /**
     * If true, two sitemap files (.xml and .xml.gz) will be created and added to robots.txt.
     * If true, .gz file will be submitted to search engines.
     * If quantity of URLs will be bigger than 50.000, option will be ignored,
     * all sitemap files except sitemap index will be compressed.
     * @var bool
     * @access public
     */
    private $createGZipFile = false;
    /**
     * URL to Your site.
     * Script will use it to send sitemaps to search engines.
     * @var string
     * @access private
     */
    private $baseURL;
    /**
     * Base path. Relative to script location.
     * Use this if Your sitemap and robots files should be stored in other
     * directory then script.
     * @var string
     * @access private
     */
    private $basePath;
    /**
     * Version of this class
     * @var string
     * @access private
     */
    private $classVersion = "2.0.0";
    /**
     * Search engines URLs
     * @var array of strings
     * @access private
     */
    private $searchEngines = [
        [
            "http://search.yahooapis.com/SiteExplorerService/V1/updateNotification?appid=USERID&url=",
            "http://search.yahooapis.com/SiteExplorerService/V1/ping?sitemap=",
        ],
        "http://www.google.com/webmasters/tools/ping?sitemap=",
        "http://submissions.ask.com/ping?sitemap=",
        "http://www.bing.com/webmaster/ping.aspx?siteMap=",
    ];
    /**
     * Array with urls
     * @var SplFixedArray of strings
     * @access private
     */
    private $urls;
    /**
     * Array with sitemap
     * @var array of strings
     * @access private
     */
    private $sitemaps = [];
    /**
     * Array with sitemap index
     * @var array of strings
     * @access private
     */
    private $sitemapIndex;
    /**
     * Current sitemap full URL
     * @var string
     * @access private
     */
    private $sitemapFullURL;
    /**
     * @var DOMDocument
     */
    private $document;
    /**
     * Lines for robots.txt file that are written if file does not exist
     * @var array
     */
    private $sampleRobotsLines = [
        "User-agent: *",
        "Allow: /",
    ];

    /**
     * Constructor.
     * @param string $baseURL You site URL, with / at the end.
     * @param string|null $basePath Relative path where sitemap and robots should be stored.
     */
    public function __construct(string $baseURL, string $basePath = "")
    {
        $this->urls = new SplFixedArray();
        $this->baseURL = $baseURL;
        $this->document = new DOMDocument("1.0");
        $this->document->preserveWhiteSpace = false;
        $this->document->formatOutput = true;

        if (strlen($basePath) > 0 && substr($basePath, -1) != DIRECTORY_SEPARATOR) {
            $basePath = $basePath . DIRECTORY_SEPARATOR;
        }
        $this->basePath = $basePath;
    }

    public function setSitemapFilename(string $filename = ''): SitemapGenerator
    {
        if (strlen($filename) === 0) {
            throw new InvalidArgumentException('filename should not be empty');
        }
        $this->sitemapFileName = $filename;
        return $this;
    }

    /**
     * @param string $filename
     * @return $this
     */
    public function setSitemapIndexFilename(string $filename = ''): SitemapGenerator
    {
        if (strlen($filename) === 0) {
            throw new InvalidArgumentException('filename should not be empty');
        }
        $this->sitemapIndexFileName = $filename;
        return $this;
    }

    /**
     * @param string $filename
     * @return $this
     */
    public function setRobotsFileName(string $filename): SitemapGenerator
    {
        if (strlen($filename) === 0) {
            throw new InvalidArgumentException('filename should not be empty');
        }
        $this->robotsFileName = $filename;
        return $this;
    }

    /**
     * @param int $value
     * @return $this
     */
    public function setMaxURLsPerSitemap(int $value): SitemapGenerator
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('max urls per sitemap value should be a positive integer value');
        }
        $this->maxURLsPerSitemap = $value;
        return $this;
    }

    /**
     * @return SitemapGenerator
     */
    public function toggleGZipFileCreation(): SitemapGenerator
    {
        $this->createGZipFile = !$this->createGZipFile;
        return $this;
    }

    /**
     * Use this to add many URLs at one time.
     * Each inside array can have 1 to 4 fields.
     * @param $urlsArray
     * @throws InvalidArgumentException
     */
    public function addUrls(array $urlsArray): SitemapGenerator
    {
        if (!is_array($urlsArray)) {
            throw new InvalidArgumentException("Array as argument should be given.");
        }
        foreach ($urlsArray as $url) {
            $this->addUrl(
                isset($url[0]) ? $url[0] : null,
                isset($url[1]) ? $url[1] : null,
                isset($url[2]) ? $url[2] : null,
                isset($url[3]) ? $url[3] : null,
                isset($url[4]) ? $url[4] : null
            );
        }
        return $this;
    }

    /**
     * Use this to add single URL to sitemap.
     * @param $url
     * @param DateTime|null $lastModified
     * @param string|null $changeFrequency ex. 'always'
     * @param float|null $priority ex. '0.5'
     * @param array|null $alternates
     * @throws InvalidArgumentException
     * @see http://php.net/manual/en/function.date.php
     * @see http://en.wikipedia.org/wiki/ISO_8601
     */
    public function addUrl(
        string $url,
        DateTime $lastModified = null,
        string $changeFrequency = null,
        float $priority = null,
        array $alternates = null
    ): SitemapGenerator
    {
        if ($url == null) {
            throw new InvalidArgumentException("URL is mandatory. At least one argument should be given.");
        }
        $urlLength = extension_loaded('mbstring') ? mb_strlen($url) : strlen($url);
        if ($urlLength > 2048) {
            throw new InvalidArgumentException(
                "URL length can't be bigger than 2048 characters.
                Note, that precise url length check is guaranteed only using mb_string extension.
                Make sure Your server allow to use mbstring extension."
            );
        }
        $tmp = new SplFixedArray(1);

        $tmp[self::URL_PARAM_LOC] = $url;

        if (isset($lastModified)) {
            $tmp->setSize(2);
            $tmp[self::URL_PARAM_LASTMOD] = $lastModified->format(DateTime::ATOM);
        }

        if (isset($changeFrequency)) {
            $tmp->setSize(3);
            $tmp[self::URL_PARAM_CHANGEFREQ] = $changeFrequency;
        }

        if (isset($priority)) {
            $tmp->setSize(4);
            $tmp[self::URL_PARAM_PRIORITY] = $priority;
        }

        if (isset($alternates)) {
            $tmp->setSize(5);
            $tmp[self::URL_PARAM_ALTERNATES] = $alternates;
        }

        if ($this->urls->getSize() === 0) {
            $this->urls->setSize(1);
        } else {
            if ($this->urls->getSize() === $this->urls->key()) {
                $this->urls->setSize($this->urls->getSize() * 2);
            }
        }

        $this->urls[$this->urls->key()] = $tmp;
        $this->urls->next();
        return $this;
    }

    /**
     * Creates sitemap and stores it in memory.
     * @throws BadMethodCallException
     * @throws InvalidArgumentException
     * @throws LengthException
     */
    public function createSitemap(): SitemapGenerator
    {
        if (!isset($this->urls)) {
            throw new BadMethodCallException("To create sitemap, call addUrl or addUrls function first.");
        }

        if ($this->maxURLsPerSitemap > self::MAX_URLS_PER_SITEMAP) {
            throw new InvalidArgumentException(
                "More than " . self::MAX_URLS_PER_SITEMAP . " URLs per single sitemap is not allowed." // todo: change the message
            );
        }

        $generatorInfo = implode(PHP_EOL, [
            sprintf('<!-- generator-class="%s" -->', get_class($this)),
            sprintf('<!-- generator-version="%s" -->', $this->classVersion),
            sprintf('<!-- generated-on="%s" -->', date('c')),
        ]);

        $sitemapHeader = implode(PHP_EOL, [
            '<?xml version="1.0" encoding="UTF-8"?>',
            $generatorInfo,
            '<urlset',
            'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"',
            'xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd"',
            'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            '</urlset>',
        ]);

        $sitemapIndexHeader = implode(PHP_EOL, [
            '<?xml version="1.0" encoding="UTF-8"?>',
            $generatorInfo,
            '<sitemapindex',
            'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"',
            'xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/siteindex.xsd"',
            'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            '</sitemapindex>',
        ]);

        $nullUrls = 0;
        foreach ($this->urls as $url) {
            if (is_null($url)) {
                $nullUrls++;
            }
        }

        $nonEmptyUrls = $this->urls->getSize() - $nullUrls;

        $chunks = ceil($nonEmptyUrls / $this->maxURLsPerSitemap);

        for ($chunkCounter = 0; $chunkCounter < $chunks; $chunkCounter++) {
            $sitemapXml = new SimpleXMLElement($sitemapHeader);
            for ($urlCounter = $chunkCounter * $this->maxURLsPerSitemap;
                 $urlCounter < ($chunkCounter + 1) * $this->maxURLsPerSitemap && $urlCounter < $nonEmptyUrls;
                 $urlCounter++
            ) {
                $row = $sitemapXml->addChild('url');

                $row->addChild(
                    'loc',
                    htmlspecialchars($this->baseURL . $this->urls[$urlCounter][self::URL_PARAM_LOC], ENT_QUOTES, 'UTF-8')
                );

                if ($this->urls[$urlCounter]->getSize() > 1) {
                    $row->addChild('lastmod', $this->urls[$urlCounter][self::URL_PARAM_LASTMOD]);
                }
                if ($this->urls[$urlCounter]->getSize() > 2) {
                    $row->addChild('changefreq', $this->urls[$urlCounter][self::URL_PARAM_CHANGEFREQ]);
                }
                if ($this->urls[$urlCounter]->getSize() > 3) {
                    $row->addChild('priority', $this->urls[$urlCounter][self::URL_PARAM_PRIORITY]);
                }
                if ($this->urls[$urlCounter]->getSize() > 4) {
                    foreach ($this->urls[$urlCounter][self::URL_PARAM_ALTERNATES] as $alternate) {
                        if (isset($alternate['hreflang']) && isset($alternate['href'])) {
                            $tag = $row->addChild('link', null, 'xhtml');
                            $tag->addAttribute('rel', 'alternate');
                            $tag->addAttribute('hreflang', $alternate['hreflang']);
                            $tag->addAttribute('href', $alternate['href']);
                        }
                    }
                }
            }

            $sitemapStr = $sitemapXml->asXML();
            $sitemapStrLen = strlen($sitemapStr);

            if ($sitemapStrLen > self::MAX_FILE_SIZE) {
                $diff = number_format($this->getDiffInPercents(self::MAX_FILE_SIZE, $sitemapStrLen), 2);
                throw new LengthException(
                    "Sitemap size limit reached " .
                    sprintf("(current limit = %d bytes, file size = %d bytes, diff = %s%%), ", $sitemapStrLen, self::MAX_FILE_SIZE, $diff)
                    . "please decrease max urls per sitemap setting in generator instance"
                );
            }
            $this->sitemaps[] = $sitemapStr;
        }
        $sitemapsCount = count($this->sitemaps);
        if ($sitemapsCount > 1) {
            if ($sitemapsCount > self::MAX_SITEMAPS_PER_INDEX) {
                throw new LengthException(
                    sprintf("Number of sitemaps per index has reached its limit (%s)", self::MAX_SITEMAPS_PER_INDEX)
                );
            }
            for ($i = 0; $i < $sitemapsCount; $i++) {
                $this->sitemaps[$i] = [
                    str_replace(".xml", ($i + 1) . ".xml", $this->sitemapFileName),
                    $this->sitemaps[$i],
                ];
            }
            $sitemapXml = new SimpleXMLElement($sitemapIndexHeader);
            foreach ($this->sitemaps as $sitemap) {
                $row = $sitemapXml->addChild('sitemap');
                $row->addChild('loc', $this->baseURL . "/" . $this->appendGzPostfixIfEnabled(htmlentities($sitemap[0])));
                $row->addChild('lastmod', date('c'));
            }
            $this->sitemapFullURL = $this->baseURL . "/" . $this->sitemapIndexFileName;
            $this->sitemapIndex = [
                $this->sitemapIndexFileName,
                $sitemapXml->asXML(),
            ];
        } else {
            $this->sitemapFullURL = $this->baseURL . "/" . $this->getSitemapFileName();
            $this->sitemaps[0] = [
                $this->sitemapFileName,
                $this->sitemaps[0],
            ];
        }

        return $this;
    }

    private function getDiffInPercents(int $total, int $part): float
    {
        return $part * 100 / $total - 100;
    }

    private function appendGzPostfixIfEnabled(string $str): string
    {
        if ($this->createGZipFile) {
            return $str . ".gz";
        }
        return $str;
    }

    private function getSitemapFileName(string $name = null): string
    {
        if ($name === null) {
            $name = $this->sitemapFileName;
        }
        return $this->appendGzPostfixIfEnabled($name);
    }

    /**
     * Returns created sitemaps as array of strings.
     * Useful in case if you want to work with sitemap without saving it as files.
     * @return array of strings
     * @access public
     */
    public function toArray(): array
    {
        if (isset($this->sitemapIndex)) {
            return array_merge([$this->sitemapIndex], $this->sitemaps);
        } else {
            return $this->sitemaps;
        }
    }

    /**
     * Will write sitemaps as files.
     * @access public
     * @throws BadMethodCallException
     */
    public function writeSitemap(): SitemapGenerator
    {
        if (!isset($this->sitemaps)) {
            throw new BadMethodCallException("To write sitemap, call createSitemap function first.");
        }
        if (isset($this->sitemapIndex)) {
            $this->document->loadXML($this->sitemapIndex[1]);
            $this->writeFile($this->document->saveXML(), $this->basePath, $this->sitemapIndex[0], true);
            foreach ($this->sitemaps as $sitemap) {
                $this->writeFile($sitemap[1], $this->basePath, $sitemap[0]);
            }
        } else {
            $this->document->loadXML($this->sitemaps[0][1]);
            $this->writeFile($this->document->saveXML(), $this->basePath, $this->sitemaps[0][0], true);
            $this->writeFile($this->sitemaps[0][1], $this->basePath, $this->sitemaps[0][0]);
        }
        return $this;
    }

    /**
     * Write file to path
     * @param string $content
     * @param string $filePath
     * @param string $fileName
     * @param bool $noGzip
     * @return bool
     * @access private
     */
    private function writeFile($content, $filePath, $fileName, $noGzip = false) // todo: remove boolean flag
    {
        if (!$noGzip && $this->createGZipFile) {
            return $this->writeGZipFile($content, $filePath, $fileName);
        }
        $file = fopen($filePath . $fileName, 'w');
        fwrite($file, $content);
        return fclose($file);
    }

    /**
     * Save GZipped file.
     * @param string $content
     * @param string $filePath
     * @param string $fileName
     * @return bool
     * @access private
     */
    private function writeGZipFile($content, $filePath, $fileName)
    {
        $fileName .= '.gz';
        $file = gzopen($filePath . $fileName, 'w');
        gzwrite($file, $content);
        return gzclose($file);
    }

    /**
     * Will inform search engines about newly created sitemaps.
     * Google, Ask, Bing and Yahoo will be noticed.
     * If You don't pass yahooAppId, Yahoo still will be informed,
     * but this method can be used once per day. If You will do this often,
     * message that limit was exceeded  will be returned from Yahoo.
     * @param string $yahooAppId Your site Yahoo appid.
     * @return array of messages and http codes from each search engine
     * @access public
     * @throws BadMethodCallException
     */
    public function submitSitemap($yahooAppId = null)
    {
        if (!isset($this->sitemaps)) {
            throw new BadMethodCallException("To submit sitemap, call createSitemap function first.");
        }
        if (!extension_loaded('curl')) {
            throw new BadMethodCallException("cURL library is needed to do submission.");
        }
        $searchEngines = $this->searchEngines;
        $searchEngines[0] = isset($yahooAppId) ?
            str_replace("USERID", $yahooAppId, $searchEngines[0][0]) :
            $searchEngines[0][1];
        $result = [];
        for ($i = 0; $i < count($searchEngines); $i++) {
            $submitSite = curl_init($searchEngines[$i] . htmlspecialchars($this->sitemapFullURL, ENT_QUOTES, 'UTF-8'));
            curl_setopt($submitSite, CURLOPT_RETURNTRANSFER, true);
            $responseContent = curl_exec($submitSite);
            $response = curl_getinfo($submitSite);
            $submitSiteShort = array_reverse(explode(".", parse_url($searchEngines[$i], PHP_URL_HOST)));
            $result[] = [
                "site" => $submitSiteShort[1] . "." . $submitSiteShort[0],
                "fullsite" => $searchEngines[$i] . htmlspecialchars($this->sitemapFullURL, ENT_QUOTES, 'UTF-8'),
                "http_code" => $response['http_code'],
                "message" => str_replace("\n", " ", strip_tags($responseContent)),
            ];
        }
        return $result;
    }

    /**
     * Returns array of URLs
     * Converts internal SplFixedArray to array
     * @return array
     */
    public function getURLsArray(): array
    {
        $urls = $this->urls->toArray();

        /**
         * @var int $key
         * @var SplFixedArray $urlSplArr
         */
        foreach ($urls as $key => $urlSplArr) {
            if (!is_null($urlSplArr)) {
                $urlArr = $urlSplArr->toArray();
                $url = [];
                foreach ($urlArr as $paramIndex => $paramValue) {
                    switch ($paramIndex) {
                        case static::URL_PARAM_LOC:
                            $url['loc'] = $paramValue;
                            break;
                        case static::URL_PARAM_CHANGEFREQ:
                            $url['changefreq'] = $paramValue;
                            break;
                        case static::URL_PARAM_LASTMOD:
                            $url['lastmod'] = $paramValue;
                            break;
                        case static::URL_PARAM_PRIORITY:
                            $url['priority'] = $paramValue;
                            break;
                        default:
                            break;
                    }
                }
                $urls[$key] = $url;
            }
        }

        return $urls;
    }

    public function getURLsCount(): int
    {
        return $this->urls->getSize();
    }

    /**
     * Adds sitemap url to robots.txt file located in basePath.
     * If robots.txt file exists,
     *      the function will append sitemap url to file.
     * If robots.txt does not exist,
     *      the function will create new robots.txt file with sample content and sitemap url.
     * @access public
     * @throws BadMethodCallException
     * @throws RuntimeException
     */
    public function updateRobots(): SitemapGenerator
    {
        if (!isset($this->sitemaps)) {
            throw new BadMethodCallException("To update robots.txt, call createSitemap function first.");
        }

        $robotsFilePath = $this->basePath . $this->robotsFileName;

        $robotsFileContent = $this->createNewRobotsContentFromFile($robotsFilePath);

        if (false === file_put_contents($robotsFilePath, $robotsFileContent)) {
            throw new RuntimeException(
                "Failed to write new contents of robots.txt to file $robotsFilePath. "
                . "Please check file permissions and free space presence."
            );
        }

        return $this;
    }

    /**
     * @param $filepath
     * @return string
     * @access private
     */
    private function createNewRobotsContentFromFile($filepath): string
    {
        if (file_exists($filepath)) {
            $robotsFileContent = "";
            $robotsFile = explode(PHP_EOL, file_get_contents($filepath));
            foreach ($robotsFile as $key => $value) {
                if (substr($value, 0, 8) == 'Sitemap:') {
                    unset($robotsFile[$key]);
                } else {
                    $robotsFileContent .= $value . PHP_EOL;
                }
            }
        } else {
            $robotsFileContent = $this->getSampleRobotsContent();
        }

        $robotsFileContent .= "Sitemap: $this->sitemapFullURL";

        return $robotsFileContent;
    }

    /**
     * @return string
     * @access private
     */
    private function getSampleRobotsContent(): string
    {
        return implode(PHP_EOL, $this->sampleRobotsLines) . PHP_EOL;
    }
}
