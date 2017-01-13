<?php

namespace pxgamer\GetiPlayer;

use DirectoryIterator as DirectoryIterator;

/**
 * Class Client.
 */
class Client
{
    const BASE_URL = 'https://open.live.bbc.co.uk';

    public $quality_array = [
        'highish' => '-audio_1=24000-video=1570000',
        'high' => '-audio_1=48000-video=5070000',
        'very_high' => '-audio_1=96000-video=5070000',
        'really_high' => '-audio_1=128000-video=8000000',
        'space' => '-audio_1=320000-video=8000000',
    ];

    public $inputUrl;

    public $searchString;

    public $videoId;

    public $discoveredUrl;

    public $baseUrl;

    public $masterKey;

    public $m3u8;

    public $m3u8Base;

    public $m3u8DataLink;

    public $m3u8MasterKey;

    public $hlsFiles;

    public $programmeId;

    public $programmeTitle;

    public $streamUrl;

    public function setUrl($inputURL)
    {
        $this->inputUrl = $inputURL;

        return $this->inputUrl;
    }

    public function setQuality($qualityString = 'highish')
    {
        $this->searchString = (isset($this->quality_array[$qualityString])) ? $this->quality_array[$qualityString] : '';

        return $this->searchString;
    }

    public function getMediaISM()
    {
        // Set the media API url
        $mediaSelectorURL = self::BASE_URL.'/mediaselector/5/select/version/2.0/mediaset/pc/vpid/'.$this->videoId;

        // Loop through
        $this->baseUrl = $this->getMediaSelection($mediaSelectorURL);

        $this->discoveredUrl = str_replace(strrchr($this->baseUrl, '/'), '', $this->baseUrl).'/';

        return $this->baseUrl;
    }

    private function getMediaSelection($mediaSelectorURL)
    {
        $ch = curl_init($mediaSelectorURL);

        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_BINARYTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
            ]
        );

        $rawResult = curl_exec($ch);
        curl_close($ch);

        // Split into XML
        $xmlArray = explode('href=', $rawResult, 1023);
        $arrayLength = count($xmlArray);
        $bestResult = false;

        // Loop through to check for URLs
        for ($x = 1; $x < $arrayLength; ++$x) {
            $search_Text = ''.chr(0x22);
            $xmlArray[$x] = strstr($xmlArray[$x], $search_Text);

            $search_Text = 'h';
            $xmlArray[$x] = strstr($xmlArray[$x], $search_Text);

            $search_Text = ''.chr(0x22);
            $xmlArray[$x] = strstr($xmlArray[$x], $search_Text, true);

            // Check if the url's are ISM links
            if (strpos($xmlArray[$x], '.ism/') != false) {
                $xmlArray[$x] = strstr($xmlArray[$x], '.ism/', true);
                $xmlArray[$x] = $xmlArray[$x].'.ism';
                $bestResult = $xmlArray[$x];
            }
        }

        // Check if the result is valid
        if (!$bestResult) {
            return false;
        }

        $finalResult = trim($bestResult);

        return $finalResult;
    }

    public function getMasterKey()
    {
        // Stores all keys for usage with downloads
        $masterKey = str_replace($this->discoveredUrl, '', $this->baseUrl);
        $this->masterKey = strstr($masterKey, '.', true);

        return $this->masterKey;
    }

    public function getM3u8()
    {
        // Set the m3u8 url and base url
        $this->m3u8 = $this->baseUrl.'/'.$this->masterKey.'.m3u8';
        $this->m3u8Base = str_replace(strrchr($this->m3u8, '/'), '', $this->m3u8).'/';

        return $this->m3u8;
    }

    public function getStream()
    {
        // Get the url for the stream data
        $this->streamUrl = $this->getStreamData($this->m3u8);
        $this->m3u8DataLink = $this->m3u8Base.$this->streamUrl;

        return $this->m3u8DataLink;
    }

    public function getM3u8MasterKey()
    {
        // Stores master key for m3u8
        $this->m3u8MasterKey = str_replace($this->m3u8Base, '', $this->streamUrl);

        return strstr($this->m3u8MasterKey, '.', true);
    }

    public function getStreamData($masterUrl)
    {
        // Get m3u8 content
        $ch = curl_init($masterUrl);

        // Set curl connection options
        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_BINARYTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
            ]
        );

        $rawResult = curl_exec($ch);
        curl_close($ch);

        // Remove comments and unnecessary data
        $results = explode('#', $rawResult);

        // Extract file links
        $bestResult = false;

        // Loop through possible files in m3u8
        foreach ($results as $result) {
            if (strpos($result, 'EXT-X-STREAM-INF:') > -1) {
                if ($this->searchString > -1) {
                    $bestResult = $this->masterKey.$this->searchString.'.m3u8';
                } else {
                    preg_match("/$this->masterKey.*\.m3u8$/", $result, $bestResult)[0];
                }
            }
        }

        // Trim the result string
        $finalResult = trim($bestResult);

        return $finalResult;
    }

    public function getVideoId()
    {
        // Connect to the input page
        $ch = curl_init($this->inputUrl);

        // Execute the cURL statement
        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER => true,
            ]
        );

        $iPlayerPageData = curl_exec($ch);
        curl_close($ch);

        // Parse for the video ID
        $searchString = '/"vpid":"([a-z0-9]+?)"/i';
        $outputData = [];

        // Run the matcher
        preg_match($searchString, $iPlayerPageData, $outputData);

        // Set the Video ID
        if (count($outputData) > 1) {
            $this->videoId = trim($outputData[1]);
        } else {
            return false;
        }

        return $this->videoId;
    }

    public function downloadFiles($fileList)
    {
        // Loop through files
        foreach ($fileList as $key) {
            for ($retries = 0; $retries <= 10; ++$retries) {
                $success = $this->downloadHlsFiles($key);
                if ($success == true) {
                    $retries = 10;
                }
            }
        }

        return true;
    }

    public function listHlsFiles()
    {
        // Get contents of *.m3u8 file
        $ch = curl_init($this->baseUrl.'/'.$this->streamUrl);

        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_BINARYTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
            ]
        );

        $raw = curl_exec($ch);
        curl_close($ch);

        // Remove comments and unnecessary data
        $listRaw = explode('#', $raw);

        // Extract file links
        $list = array();

        foreach ($listRaw as $listItem) {
            if (strpos($listItem, $this->masterKey) !== false) {
                preg_match("/$this->masterKey.*\.ts$/", $listItem, $listItem);

                $listItem = trim($listItem[0]);

                array_push($list, $listItem);
            }
        }

        $this->hlsFiles = $list;

        return $this->hlsFiles;
    }

    public function writeFiles()
    {
        // Open the file handler in files
        $opHandle = fopen('files\\'.$this->programmeTitle.'.ts', 'a');

        // Write the downloaded content to the handle .ts file
        foreach ($this->hlsFiles as $key) {
            fwrite($opHandle, file_get_contents('downloads\\'.$key, 'r'));
        }

        // Close the handle
        fclose($opHandle);

        return true;
    }

    public function getProgrammeId()
    {
        // Find full program title from BBC from the PID
        $programmePID = explode('_', $this->masterKey);
        $this->programmeId = $programmePID[1];

        return $this->programmeId;
    }

    public function getFullProgramTitle()
    {
        // Download the Title of the program
        $streamUrl = 'https://www.bbc.co.uk/iplayer/episode/'.$this->programmeId;

        // Download iPlayer page for the program (no proxy needed)
        $ch = curl_init($streamUrl);

        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
            ]
        );

        $iPlayerEpisode = curl_exec($ch);
        curl_close($ch);

        // Get the exact title with title tags
        $iPlayerEpisode = strstr($iPlayerEpisode, '<title>');
        $iPlayerEpisode = strstr($iPlayerEpisode, '</title>', true);

        // Parse title
        $iPlayerEpisode = str_replace('<title>', '', $iPlayerEpisode);
        $iPlayerEpisode = str_replace('</title>', '', $iPlayerEpisode);
        $iPlayerEpisode = trim($iPlayerEpisode);

        // Validate title by removing invalid characters
        $iPlayerEpisode = str_replace(
            [
                ':',
                '<',
                '>',
                '\\',
                '/',
                '*',
                '?',
                '|',
            ],
            '',
            $iPlayerEpisode
        );
        $iPlayerEpisode = str_replace('"', '-', $iPlayerEpisode);

        // Set the title
        $this->programmeTitle = trim($iPlayerEpisode);

        return $this->programmeTitle;
    }

    private function downloadHlsFiles($fileName)
    {
        $streamUrl = $this->baseUrl.'/'.$fileName;

        if (!isset($fileName)) {
            return false;
        }

        // Download TLS files
        $ch = curl_init($streamUrl);

        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_BINARYTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
            ]
        );

        $raw_ts_file = curl_exec($ch);
        curl_close($ch);

        // Check the validity
        $success = true;
        if ($raw_ts_file == false) {
            return false;
        }

        // Set the output file name
        $output_filename = 'downloads\\'.$fileName;

        // Write file to downloads directory
        $fp = fopen($output_filename, 'w');
        fwrite($fp, $raw_ts_file);
        fclose($fp);

        return $success;
    }

    public function createDirectories()
    {
        if (!is_dir('downloads')) {
            mkdir('downloads');
        }
        if (!is_dir('files')) {
            mkdir('files');
        }

        return true;
    }

    public function cleanDirectory()
    {
        // Remove the downloaded files
        $DirIterator = new DirectoryIterator('downloads');
        foreach ($DirIterator as $fileInfo) {
            if (!$fileInfo->isDot() && $fileInfo != 'README.md') {
                unlink('downloads/'.$fileInfo->getFilename());
            }
        }

        // Rebuild the downloads directory
        return true;
    }

    public function reset()
    {
        // Reset all values
        $this->inputUrl =
        $this->videoId =
        $this->inputUrl =
        $this->searchString =
        $this->videoId =
        $this->discoveredUrl =
        $this->baseUrl =
        $this->masterKey =
        $this->m3u8 =
        $this->m3u8Base =
        $this->m3u8DataLink =
        $this->m3u8MasterKey =
        $this->hlsFiles =
        $this->programmeId =
        $this->programmeTitle =
        $this->streamUrl =
        $this->discoveredUrl = null;

        return true;
    }
}
