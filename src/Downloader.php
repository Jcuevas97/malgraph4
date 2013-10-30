<?php
class Downloader
{
	private static function prepareHandle($url)
	{
		$handle = curl_init();
		curl_setopt($handle, CURLOPT_URL, $url);
		curl_setopt($handle, CURLOPT_HEADER, 1);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		curl_setopt($handle, CURLOPT_ENCODING, '');
		curl_setopt($handle, CURLOPT_USERAGENT, 'MALgraph');
		return $handle;
	}

	private static function parseResult($result, $url)
	{
		$pos = strpos($result, "\r\n\r\n");
		$content = substr($result, $pos + 4);
		$headerLines = explode("\r\n", substr($result, 0, $pos));

		preg_match('/\d{3}/', array_shift($headerLines), $matches);
		$code = intval($matches[0]);
		$headers = HttpHeadersHelper::parseHeaderLines($headerLines);

		return new Document($url, $code, $headers, $content);
	}

	private static function urlToPath($url)
	{
		return Config::$mirrorPath . DIRECTORY_SEPARATOR . rawurlencode($url) . '.dat';
	}

	public static function purgeCache(array $urls)
	{
		foreach ($urls as $url)
		{
			$path = self::urlToPath($url);
			if (file_exists($path))
			{
				unlink($path);
			}
		}
	}

	public static function downloadMulti(array $urls)
	{
		$handles = [];
		$documents = [];
		$urls = array_combine($urls, $urls);

		//if mirror exists, load its content and purge url from download queue
		if (Config::$mirrorEnabled)
		{
			foreach ($urls + [] as $url)
			{
				$path = self::urlToPath($url);
				if (file_exists($path))
				{
					$rawResult = file_get_contents($path);
					$documents[$url] = self::parseResult($rawResult, $url);
					unset($urls[$url]);
				}
			}
		}

		//prepare curl handles
		$multiHandle = curl_multi_init();
		foreach ($urls as $url)
		{
			$handle = self::prepareHandle($url);
			curl_multi_add_handle($multiHandle, $handle);
			$handles[$url] = $handle;
		}

		//run the query
		$running = null;
		do
		{
			$status = curl_multi_exec($multiHandle, $running);
		}
		while ($status == CURLM_CALL_MULTI_PERFORM);
		while ($running and $status == CURLM_OK)
		{
			if (curl_multi_select($multiHandle) != -1)
			{
				do
				{
					$status = curl_multi_exec($multiHandle, $running);
				}
				while ($status == CURLM_CALL_MULTI_PERFORM);
			}
		}

		//get the documents from curl
		foreach ($handles as $url => $handle)
		{
			$rawResult = curl_multi_getcontent($handle);
			if (Config::$mirrorEnabled)
			{
				file_put_contents(self::urlToPath($url), $rawResult);
			}
			$documents[$url] = self::parseResult($rawResult, $urls[$url]);
			curl_multi_remove_handle($multiHandle, $handle);
		}

		//close curl handles
		curl_multi_close($multiHandle);

		return $documents;
	}
}
