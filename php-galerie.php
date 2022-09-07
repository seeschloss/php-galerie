<?php

class Video extends Media {
	public $thumbnail_file = '';

	function classes() {
		$classes = parent::classes();
		$classes[] = 'video';

		return $classes;
	}

	function html_thumbnail($width, $height, $crop_thumbnail = false, $embed_thumbnail = false) {
		$classes = implode(' ', $this->classes());

		$extra = '';
		if (file_exists($this->thumbnail_file)) {
			$extra .= ' poster="'.htmlspecialchars(basename($this->thumbnail_file)).'"';
			$this->files[basename($this->thumbnail_file)] = ['path' => $this->thumbnail_file];
		}

		$html = <<<HTML
	<figure class="{$classes}">
		<a href="{$this->url_original()}"><video controls height="{$height}" width="{$width}" src="{$this->url_original()}" $extra></video>
		<figcaption><a href="{$this->url_original()}">{$this->title()}</a></figcaption>
	</figure>

HTML;

		$this->files[basename($this->original_path)] = ['path' => $this->path_original()];

		return $html;
	}

	function url_size($width, $height, $crop = false, $embed = false) {
		return $this->url_original();
	}
}

class Photo extends Media {
	function html_thumbnail($width, $height, $crop_thumbnail = false, $embed_thumbnail = false) {
		$classes = implode(' ', $this->classes());

		$html = <<<HTML
	<figure class="{$classes}">
		<a href="{$this->url_original()}"><img src="{$this->url_size($width, $height, $crop_thumbnail, $embed_thumbnail)}" /></a>
		<figcaption><a href="{$this->url_original()}">{$this->title()}</a></figcaption>
	</figure>

HTML;

		$this->files[basename($this->original_path)] = ['path' => $this->path_original()];

		if (!$embed_thumbnail) {
			$this->files[$this->url_size($width, $height, $crop_thumbnail, false)] = ['data' => $this->generate_size($width, $height, $crop_thumbnail)];
		}

		return $html;
	}

	function classes() {
		$classes = parent::classes();
		$classes[] = 'photo';

		return $classes;
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

	private function generate_size($width, $height, $crop = false) {
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
}

class Media {
	public $original_path = "";

	public $files = [];

	function __construct($path) {
		$this->original_path = $path;
	}

	function path_original() {
		return $this->original_path;
	}

	function url_original() {
		return basename($this->original_path);
	}

	function url_size($width, $height, $crop = false, $embed = false) {
		return "";
	}

	function classes() {
		$classes = [];

		$title = basename($this->original_path);

		$first_dot = strpos($title, '.');
		$last_dot = strrpos($title, '.');

		if ($first_dot > 0 and $last_dot > 0 and $first_dot != $last_dot) {
			$classes[] = "has-title";
		}

		return $classes;
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
		return "";
	}
}

class Gallery {
	public $files_raw = [];
	public $media = [];
	public $galleries = [];
	public $parent = null;

	public $thumbnail = null;
	public $thumbnail_src = "";
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
		if ($this->thumbnail) {
			return $this->thumbnail->url_size($this->thumbnail_width, $this->thumbnail_height, $this->crop_thumbnails, true);
		} else {
			return $this->thumbnail_src;
		}
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
			$this->thumbnail_src = $html->attributes->getNamedItem("data-thumbnail-src")->textContent;
			if ($html->attributes->getNamedItem("data-title")) {
				$this->title = $html->attributes->getNamedItem("data-title")->textContent;
			}
		}
	}

	function read_directory($directory, $recursive = false, $max_depth = NULL) {
		$this->url = basename($directory);
		$this->title = $this->url;
		$media = [];

		foreach (glob($directory."/*.jpg") + glob($directory."/*.JPG") + glob($directory."/*.jpeg") + glob($directory."/*.JPEG") as $file) {
			if (strpos(basename($file), ".") !== 0 and strpos(basename($file), "_") !== 0) {
				$media[$file] = new Photo($file);
			}
		}

		foreach (glob($directory."/*.mp4") as $file) {
			if (strpos(basename($file), ".") !== 0 and strpos(basename($file), "_") !== 0) {
				$media[$file] = new Video($file);

				$thumbnail_file = str_replace('.mp4', '.jpg', $file);
				if (file_exists($thumbnail_file)) {
					unset($media[$thumbnail_file]);
					$media[$file]->thumbnail_file = $thumbnail_file;
				}
			}
		}

		$this->media = $media;

		if (file_exists($directory."/.thumbnail.jpg")) {
			$this->thumbnail = new Photo($directory."/.thumbnail.jpg");
		} else if (count($this->media)) {
			$this->thumbnail = $this->media[array_key_first($this->media)];
		}

		$galleries = [];

		if ($recursive) {
			if ($max_depth === NULL or $max_depth > 1) {
				foreach (glob($directory."/*/") as $subdirectory) {
					if (strpos(basename($subdirectory), ".") !== 0 and strpos(basename($subdirectory), "_") !== 0) {
						if (Gallery::is_gallery($subdirectory)) {
							$gallery = new Gallery();
							$gallery->parent = $this;
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
		if ($this->thumbnail) {
			return $this->thumbnail->url_size($this->thumbnail_width, $this->thumbnail_height, $this->crop_thumbnails, true);
		} else foreach ($this->media as $media) {
			return $media->url_size($this->thumbnail_width, $this->thumbnail_height, $this->crop_thumbnails, true);
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
			margin: 0;
			/*
			column-count: auto;
			column-width: 250px;
			column-gap: 5px;
			*/
		}

		#header {
			grid-column-start: 1;
			grid-column-end: -1;
			justify-self: center;
			display: grid;
			grid-template-columns: auto 1fr;
			grid-gap: 20px;
			align-items: center;
		}

		#header h1 {
			margin: 0;
			font-size 10pt;
		}

		#header figure img {
			max-height: 50px;
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

		#popup {
			position: absolute;
			width: 100%;
			height: 100%;
			background-color: rgba(0, 0, 0, 0.9);
			display: grid;
			align-items: center;
			justify-items: center;
		}

		#popup img {
			max-width: 100%;
			max-height: 100%;
		}

		#popup #prev, #popup #next {
			position: absolute;
			height: 100%;
			width: 100px;
			display: grid;
			align-items: center;
			opacity: 0.2;
			cursor: pointer;
		}

		#popup #prev:hover, #popup #next:hover {
			opacity: 1;
		}

		#popup #prev {
			left: 0;
		}

		#popup #next {
			right: 0;
		}

		#popup #prev:before, #popup #next:before {
			font-size: 50pt;
			font-weight: bold;
			padding: 0 10px;
			-webkit-text-stroke-width: 2px;
			-webkit-text-stroke-color: black;
		}

		#popup #prev:before {
			content: "◂";
		}

		#popup #next:before {
			content: "▸";
		}

		#popup #next {
			justify-items: end;
		}
CSS;

		if ($this->crop_thumbnails) {
			$css .= <<<CSS
		figure > a img {
			width: 100%;
		}
		figure > a video {
			width: 100%;
		}
CSS;
		} else {
			$css .= <<<CSS
		figure > a img {
			max-width: 100%;
		}
		figure > a video {
			max-width: 100%;
		}
CSS;
		}

		$link_parent = "";
		if ($this->parent) {
			$this->parent->url = '..';
			$link_parent = $this->parent->html_thumbnail($this->thumbnail_width, $this->thumbnail_height);
		}

		$html = <<<HTML
<!DOCTYPE html>
<html class="php-galerie" data-thumbnail-src="{$this->thumbnail_base64()}">
<head>
	<meta charset="utf-8" />
	<style>
		{$css}
	</style>
</head>
<body>
	<div id="header">{$link_parent}<h1>{$this->title}</h1></div>
HTML;

		foreach ($this->galleries as $gallery) {
			$html .= $gallery->html_thumbnail($this->thumbnail_width, $this->thumbnail_height);
		}

		foreach ($this->media as $media) {
			$html .= $media->html_thumbnail($this->thumbnail_width, $this->thumbnail_height, $this->crop_thumbnails, $this->embed_thumbnails);
		}

		$html .= <<<HTML
	<script>
		function showPhoto(a_element) {
			var div = document.createElement('div');
			div.id = "popup";
			var img = document.createElement('img');
			img.src = a_element.href;
			var next = document.createElement('div');
			next.id = "next";
			next.class = "arrow";
			var prev = document.createElement('div');
			prev.id = "prev";
			prev.class = "arrow";
			div.appendChild(img);
			div.appendChild(prev);
			div.appendChild(next);
			document.body.appendChild(div);

			div.onclick = function(e) {
				e.preventDefault();
				e.stopPropagation();
				document.body.removeChild(div);
			};

			prev.onclick = function(e) {
				e.preventDefault();
				e.stopPropagation();
				var prevElement = a_element.parentElement.previousElementSibling;
				if (prevElement) {
					var prevPhoto = prevElement.querySelector('a');
					if (prevPhoto) {
						showPhoto(prevPhoto);
					}
				}
				document.body.removeChild(div);
			};

			next.onclick = function(e) {
				e.preventDefault();
				e.stopPropagation();
				var nextElement = a_element.parentElement.nextElementSibling;
				if (nextElement) {
					var nextPhoto = nextElement.querySelector('a');
					if (nextPhoto) {
						showPhoto(nextPhoto);
					}
				}
				document.body.removeChild(div);
			};
		}

		var photos = document.querySelectorAll('.photo a');
		for (var i in photos) {
			photos[i].onclick = function(e) {
				e.preventDefault();
				e.stopPropagation();
				console.log([e, this]);
				showPhoto(this);
			};
		}
	</script>
</body>
</html>
HTML;

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
		foreach ($this->media as $media) {
			foreach ($media->files as $relative_path => $file) {
				$directory = dirname($output_directory."/".$relative_path);
				if (!file_exists($directory)) {
					mkdir($directory, 0777, true);
				}
				$file_output_path = $output_directory."/".$relative_path;
				if (file_exists($output_directory."/".$relative_path)) {
					if (filemtime($media->path_original()) > filemtime($file_output_path)) {
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
Generate a static HTML media gallery from directories containing JPEG files.

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
