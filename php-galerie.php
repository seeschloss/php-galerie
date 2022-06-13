<?php

class Photo {
	public $original_path = "";

	public $files = [];

	function __construct($path) {
		$this->original_path = $path;
	}

	function path_original() {
		return $this->original_path;
	}

	function path_size($width, $height, $crop = false) {
		$cache_directory = dirname($this->original_path)."/.cache";

		if (!file_exists($cache_directory)) {
			mkdir($cache_directory, 0777, true);
		}

		$filename = basename($this->original_path);
		$width = (int)$width;
		$height = (int)$height;

		$path_size = "{$cache_directory}/w={$width},h={$height}";

		if ($crop) {
			$path_size .= ",c";
		}

		$path_size .= ",{$filename}";

		return $path_size;
	}

	function generate_size($width, $height, $crop = false) {
		if (!file_exists($this->path_size($width, $height, $crop))) {
			$cache_directory = dirname($this->original_path)."/.cache";
			if (!file_exists($cache_directory)) {
				mkdir($cache_directory);
			}

			$r = imagecreatefromjpeg($this->original_path);
			$width = (int)$width;
			$height = (int)$height;

			list($width_orig, $height_orig) = getimagesize($this->original_path);

			if ($crop) {
				$smallest_edge = min($width_orig, $height_orig);

				$r_resized = imagecreatetruecolor($width, $height);
				imagecopyresampled($r_resized, $r, 0, 0, ($width_orig - $smallest_edge)/2, ($height_orig - $smallest_edge)/2, $width, $height, $smallest_edge, $smallest_edge);
			} else {
				$ratio_orig = $width_orig/$height_orig;

				$width_proportional = $width;
				$height_proportional = $height;

				if ($width/$height > $ratio_orig) {
					$width_proportional = $height * $ratio_orig;
				} else {
					$height_proportional = $width / $ratio_orig;
				}

				$r_resized = imagecreatetruecolor($width_proportional, $height_proportional);
				imagecopyresampled($r_resized, $r, 0, 0, 0, 0, $width_proportional, $height_proportional, $width_orig, $height_orig);
			}

			imagejpeg($r_resized, $this->path_size($width, $height, $crop), 95);
		}

		return file_get_contents($this->path_size($width, $height, $crop));
	}

	function url_original() {
		return basename($this->original_path);
	}

	function url_size($width, $height, $crop = false, $embed = false) {
		if ($embed) {
			return "data:image/jpeg;base64,".base64_encode($this->generate_size($width, $height, $crop));
		} else {
			$url_size = ".cache/w={$width},h={$height}";
			if ($crop) {
				$url_size .= ",c";
			}
			$url_size .= ",".basename($this->original_path);
			return $url_size;
		}
	}

	function classes() {
		$classes = [];

		$title = basename($this->original_path);

		$first_dot = strpos($title, '.');
		$last_dot = strrpos($title, '.');

		if ($first_dot > 0 and $last_dot > 0 and $first_dot != $last_dot) {
			$classes[] = "has-title";
		}


		return implode(' ', $classes);
	}

	function title() {
		$title = basename($this->original_path);

		$first_dot = strpos($title, '.');
		$last_dot = strrpos($title, '.');

		if ($first_dot > 0 and $last_dot > 0 and $first_dot != $last_dot) {
			$title = substr($title, $first_dot + 1, $last_dot - $first_dot - 1);
			$title = str_replace('_', ' ', $title);
		}

		return $title;
	}

	function html_thumbnail($width, $height, $crop_thumbnail = false, $embed_thumbnail = false) {
		$html = <<<HTML
	<figure class="{$this->classes()}">
		<a href="{$this->url_original()}"><img src="{$this->url_size($width, $height, $crop_thumbnail, $embed_thumbnail)}" /></a>
		<figcaption><a href="{$this->url_original()}">{$this->title()}</a></figcaption>
	</figure>

HTML;

		$this->files[basename($this->original_path)] = ['path' => $this->path_original()];

		if (!$embed_thumbnail) {
			$this->files[".cache/w={$width},h={$height},".basename($this->original_path)] = ['data' => $this->generate_size($width, $height, $crop_thumbnail)];
		}

		return $html;
	}
}

class Gallery {
	public $files_raw = [];
	public $photos = [];
	public $galleries = [];

	public $thumbnail = "";
	public $title = "";
	public $url = "";

	public $html;

	public $embed_thumbnails = false;
	public $crop_thumbnails = false;
	public $thumbnail_width = 250;
	public $thumbnail_height = 250;

	static function is_gallery($directory) {
		if (file_exists($directory."/index.html")) {
			$lines = file($directory."/index.html");

			if (substr($lines[1], 0, 24) == '<html class="php-galerie') {
				return true;
			}
		} else if (file_exists($directory."/index.php")) {
			return true;
		} else {
			$files = glob($directory."/*.jpg") + glob($directory."/*.JPG") + glob($directory."/*.jpeg") + glob($directory."/*.JPEG");

			if (count($files)) {
				return true;
			}
		}

		return false;
	}

	function url_thumbnail() {
		return $this->thumbnail;
	}

	function html_thumbnail($width, $height) {
		$html = <<<HTML
	<figure class="gallery">
		<a href="{$this->url}"><img src="{$this->url_thumbnail()}" /></a>
		<figcaption><a href="{$this->url}">{$this->title}</a></figcaption>
	</figure>

HTML;

		return $html;
	}

	function read_index($index = null) {
		$lines = file($index);

		if (substr($lines[1], 0, 24) == '<html class="php-galerie') {
			$dom = new DOMDocument();
			$dom->loadHTMLFile($index, LIBXML_NOWARNING | LIBXML_NOERROR);

			$this->url = basename(dirname($index));
			$this->title = $this->url;

			$html = $dom->getElementsByTagName("html")->item(0);
			$this->thumbnail = $html->attributes->getNamedItem("data-thumbnail-src")->textContent;
			if ($html->attributes->getNamedItem("data-title")) {
				$this->title = $html->attributes->getNamedItem("data-title")->textContent;
			}
		}
	}

	function read_directory($directory, $recursive = false, $max_depth = NULL) {
		$this->url = basename($directory);
		$this->title = $this->url;
		$photos = [];

		foreach (glob($directory."/*.jpg") + glob($directory."/*.JPG") + glob($directory."/*.jpeg") + glob($directory."/*.JPEG") as $file) {
			if (strpos(basename($file), ".") !== 0 and strpos(basename($file), "_") !== 0) {
				$photos[$file] = new Photo($file);
			}
		}

		$this->photos = $photos;
		if (count($this->photos)) {
			$this->thumbnail = $this->photos[array_key_first($this->photos)]->url_size($this->thumbnail_width, $this->thumbnail_height, $this->crop_thumbnails, true);
		}

		$galleries = [];

		if ($recursive) {
			if ($max_depth === NULL or $max_depth > 1) {
				foreach (glob($directory."/*/") as $subdirectory) {
					if (strpos(basename($subdirectory), ".") !== 0 and strpos(basename($subdirectory), "_") !== 0) {
						if (Gallery::is_gallery($subdirectory)) {
							$gallery = new Gallery();
							$gallery->embed_thumbnails = $this->embed_thumbnails;
							$gallery->crop_thumbnails = $this->crop_thumbnails;
							$gallery->thumbnail_width = $this->thumbnail_width;
							$gallery->thumbnail_height = $this->thumbnail_height;
							$gallery->read_directory($subdirectory, $recursive, $max_depth - 1);
							$galleries[] = $gallery;
						}
					}
				}
			}
		}

		$this->galleries = $galleries;

		if (file_exists($directory."/index.html")) {
			// This will show subdirectories which have an index.html (and their thumbnail) even if recursivity is disabled.
			$this->read_index($directory."/index.html");
		}
	}

	function thumbnail_base64() {
		foreach ($this->photos as $photo) {
			return "data:image/jpeg;base64,".base64_encode($photo->generate_size($this->thumbnail_width, $this->thumbnail_height, $this->crop_thumbnails));
		}

		return "";
	}

	function html() {
		$css = <<<CSS
		body {
			display: grid;
			gap: 0.5em;
			grid-template-columns: repeat(auto-fit, {$this->thumbnail_width}px);
			grid-auto-flow: dense;
			background-color: black;
			color: white;
			/*
			column-count: auto;
			column-width: 250px;
			column-gap: 5px;
			*/
		}

		figure {
			margin: 0 0 5px 0;
			border: 2px white solid;
			border-radius: 2px;
			break-inside: avoid;
			background-color: #555;
		}

		figure.has-title a {
			font-style: normal;
		}

		figure > a {
			font-size: 0;
			display: block;
			text-align: center;
		}

		figure figcaption {
			color: #fff;
			font: italic smaller sans-serif;
			text-align: center;
		}

		figure:hover figcaption a {
			text-decoration: underline;
		}

		figure figcaption a {
			padding: 3px;
			color: white;
			text-decoration: none;
			display: block;
		}
CSS;

		if ($this->crop_thumbnails) {
			$css .= <<<CSS
		figure > a img {
			width: 100%;
		}
CSS;
		} else {
			$css .= <<<CSS
		figure > a img {
			max-width: 100%;
		}
CSS;
		}

		$html = <<<HTML
<!DOCTYPE html>
<html class="php-galerie" data-thumbnail-src="{$this->thumbnail_base64()}">
<head>
	<style>
		{$css}
	</style>
</head>
<body>
HTML;

		foreach ($this->galleries as $gallery) {
			$html .= $gallery->html_thumbnail($this->thumbnail_width, $this->thumbnail_height);
		}

		foreach ($this->photos as $photo) {
			$html .= $photo->html_thumbnail($this->thumbnail_width, $this->thumbnail_height, $this->crop_thumbnails, $this->embed_thumbnails);
		}

		$html .= "</body></html>";

		return $html;
	}

	function write($output_directory, $recursive = false, $max_depth = NULL) {
		if ($max_depth !== NULL and $max_depth <= 0) {
			return;
		}

		if (!file_exists($output_directory)) {
			mkdir($output_directory, 0777, true);
		}
		Log::stderr("Writing gallery '{$output_directory}'");
		file_put_contents($output_directory."/index.html", $this->html());
		Log::stderr(".");
		foreach ($this->photos as $photo) {
			foreach ($photo->files as $relative_path => $file) {
				$directory = dirname($output_directory."/".$relative_path);
				if (!file_exists($directory)) {
					mkdir($directory, 0777, true);
				}
				$file_output_path = $output_directory."/".$relative_path;
				if (file_exists($output_directory."/".$relative_path)) {
					if (filemtime($photo->path_original()) > filemtime($file_output_path)) {
						if (isset($file['path']) and file_exists($file['path'])) {
							copy($file['path'], $file_output_path);
							Log::stderr("+");
						} else if (isset($file['data'])) {
							file_put_contents($file_output_path, $file['data']);
							Log::stderr("+");
						}
					} else {
						Log::stderr("=");
					}
				} else {
					if (isset($file['path']) and file_exists($file['path'])) {
						copy($file['path'], $file_output_path);
						Log::stderr(".");
					} else if (isset($file['data'])) {
						file_put_contents($file_output_path, $file['data']);
						Log::stderr(".");
					}
				}
			}
		}
		Log::stderr("\n");

		if ($recursive) {
			foreach ($this->galleries as $gallery) {
				$gallery->write($output_directory.'/'.$gallery->url, $recursive, $max_depth - 1);
			}
		}
	}
}

class Log {
	static function stderr($message) {
		file_put_contents('php://stderr', $message);
	}
}

function help_message($options) {
	$longest_switches_line_lenght = 0;
	foreach ($options as $option) {
		$switches = array_shift($option['description']);
		$longest_switches_line_lenght = max($longest_switches_line_lenght, strlen($switches));
	}

	echo <<<TXT
php-galerie
Generate a static HTML photo gallery from directories containing JPEG files.

Options:

TXT;
	foreach ($options as $option) {
		$switches = str_pad(array_shift($option['description']), $longest_switches_line_lenght, " ");
		$first_line = array_shift($option['description']);
		echo "  {$switches} {$first_line}\n";
		foreach ($option['description'] as $line) {
			$padding = str_pad("", $longest_switches_line_lenght, " ");
			echo "  {$padding}     {$line}\n";
		}
	}
}

if (php_sapi_name() == 'cli') {
	$options = [
		'help' => [
			'short' => 'h',
			'long' => 'help',
			'description' => ['-h, --help', 'Show this help message'],
		],
		'output' => [
			'short' => 'o:',
			'long' => 'output-directory::',
			'description' => ['-o <directory>, --output-directory=<directory>', 'Output directory', '(current directory by default)'],
		],
		'input' => [
			'short' => 'i:',
			'long' => 'input-directory::',
			'description' => ['-i <directory>, --input-directory=<directory>', 'Input directory', '(current directory by default)'],
		],
		'recursive' => [
			'short' => 'r',
			'long' => 'recursive',
			'description' => ['-r, --recursive', 'Follow subdirectories recursively'],
		],
		'max-depth' => [
			'short' => 'd:',
			'long' => 'max-depth::',
			'description' => ['-d <depth>, --max-depth=<max-depth>', 'Maximum recursive depth, implies --recursive', '0: no limit', '1: no recursivity', '(no limit by default)'],
		],
		'embed-thumbnails' => [
			'short' => 'e',
			'long' => 'embed-thumbnails',
			'description' => ['-e, --embed-thumbnails', 'Embed thumbnails in index.html'],
		],
		'thumbnail-size' => [
			'short' => 't:',
			'long' => 'thumbnail-size::',
			'description' => ['-t <width>x<height>, --thumbnail-size=<width>x<height>', 'Resize thumbnails to size', '(250 x 250 by default)'],
		],
		'crop' => [
			'short' => 'c',
			'long' => 'crop',
			'description' => ['-c, --crop', 'Crop thumbnails to fill size'],
		],
	];

	$short_options = "";
	$long_options = [];
	foreach ($options as $option) {
		$short_options .= $option['short'];
		$long_options[] = $option['long'];
	}
	$cmdline_options = getopt($short_options, $long_options);

	if (isset($cmdline_options['h']) or isset($cmdline_options['help'])) {
		help_message($options);
		exit(0);
	}

	$input_directory = '.';
	if (!empty($cmdline_options['i'])) {
		$input_directory = $cmdline_options['i'];
	}
	if (!empty($cmdline_options['input-directory'])) {
		$input_directory = $cmdline_options['input-directory'];
	}

	$output_directory = null;
	if (!empty($cmdline_options['o'])) {
		$output_directory = $cmdline_options['o'];
	}
	if (!empty($cmdline_options['output-directory'])) {
		$output_directory = $cmdline_options['output-directory'];
	}

	$recursive = false;
	if (isset($cmdline_options['r']) or isset($cmdline_options['recursive'])) {
		$recursive = true;
	}

	$max_depth = NULL;
	if (isset($cmdline_options['d'])) {
		$max_depth = $cmdline_options['d'];
		$recursive = true;
	}
	if (isset($cmdline_options['max-depth'])) {
		$max_depth = $cmdline_options['max-depth'];
		$recursive = true;
	}

	$embed_thumbnails = false;
	if (isset($cmdline_options['e']) or isset($cmdline_options['embed-thumbnails'])) {
		$embed_thumbnails = true;
	}

	$crop_thumbnails = false;
	if (isset($cmdline_options['c']) or isset($cmdline_options['crop'])) {
		$crop_thumbnails = true;
	}

	$thumbnail_size = "250x250";
	if (isset($cmdline_options['t'])) {
		$thumbnail_size = $cmdline_options['t'];
	}
	if (isset($cmdline_options['thumbnail-size'])) {
		$thumbnail_size = $cmdline_options['thumbnail-size'];
	}

	if (strpos($thumbnail_size, 'x') === false) {
		var_dump($thumbnail_size);
		help_message($options);
		exit(1);
	}

	list($thumbnail_width, $thumbnail_height) = explode('x', $thumbnail_size);

	if ((int)$thumbnail_width != $thumbnail_width or (int)$thumbnail_height != $thumbnail_height or $thumbnail_width == 0 or $thumbnail_height == 0) {
		help_message($options);
		exit(1);
	}

	$gallery = new Gallery();
	$gallery->embed_thumbnails = $embed_thumbnails;
	$gallery->crop_thumbnails = $crop_thumbnails;
	$gallery->thumbnail_width = $thumbnail_width;
	$gallery->thumbnail_height = $thumbnail_height;
	$gallery->read_directory($input_directory, $recursive, $max_depth);

	if ($output_directory) {
		$gallery->write($output_directory, $recursive, $max_depth);
	}
} else {
	$request_path_relative = '.'.urldecode(str_replace(dirname($_SERVER['SCRIPT_NAME']), '', $_SERVER['REQUEST_URI']));
	if (file_exists($request_path_relative) and !is_dir($request_path_relative)) {
		$photo = new Photo($request_path_relative);
		header("Content-Type: image/jpeg");
		readfile($request_path_relative);
	} else {
		$gallery = new Gallery();
		$gallery->read_directory($request_path_relative);
		echo $gallery->html(true);
	}
}
