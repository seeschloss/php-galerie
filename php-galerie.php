<?php

class Photo {
	public $original_path = "";
	public $dynamic = false;

	public $files = [];

	function __construct($path, $dynamic = false) {
		$this->original_path = $path;
		$this->dynamic = $dynamic;
	}

	function path_original() {
		return $this->original_path;
	}

	function path_size($width, $height) {
		$cache_directory = dirname($this->original_path)."/.cache";

		$filename = basename($this->original_path);
		$width = (int)$width;
		$height = (int)$height;

		$path_size = "{$cache_directory}/{$filename},w={$width},h={$height}";

		return $path_size;
	}

	function generate_size($width, $height) {
		if (!file_exists($this->path_size($width, $height))) {
			$cache_directory = dirname($this->original_path)."/.cache";
			if (!file_exists($cache_directory)) {
				mkdir($cache_directory);
			}

			$r = imagecreatefromjpeg($this->original_path);
			$width = (int)$width;
			$height = (int)$height;

			list($width_orig, $height_orig) = getimagesize($this->original_path);

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

			imagejpeg($r_resized, $this->path_size($width, $height), 95);
		}

		return $this->path_size($width, $height);
	}

	function url_original() {
		return $this->dynamic ? "?image=".$this->original_path : 'files/'.basename($this->original_path);
	}

	function url_size($width, $height) {
		if ($width < 300 and $height < 300) {
			return "data:image/jpeg;base64,".base64_encode(file_get_contents($this->path_size($width, $height)));
		}

		return $this->dynamic ? "?image={$this->original_path}&w={$width}&h={$height}" : "files/w={$width},h={$height},".basename($this->original_path);
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

	function html_thumbnail($width, $height) {
		$html = <<<HTML
	<figure class="{$this->classes()}">
		<a href="{$this->url_original()}">
			<img src="{$this->url_size($width, $height)}" />
		</a>
		<figcaption>
			<a href="{$this->url_original()}">{$this->title()}</a>
		</figcaption>
	</figure>
HTML;

		$this->files['files/'.basename($this->original_path)] = $this->path_original();

		//$this->files['files/w=250,h=250,'.basename($this->original_path)] = $this->path_size(250, 250);

		return $html;
	}

	function files() {
		$files = [];

		foreach ($this->files as $html_path => $filesystem_path) {
			$files[$html_path] = file_get_contents($filesystem_path);
		}

		return $files;
	}
}

class Gallery {
	public $files_raw = [];
	public $photos = [];
	public $html;

	function directory($directory) {
		$files = [];

		foreach (glob($directory."/*.jpg") + glob($directory."/*.JPG") + glob($directory."/*.jpeg") + glob($directory."/*.JPEG") as $file) {
			if (strpos(basename($file), ".") !== 0) {
				$files[] = $file;
			}
		}

		$this->files_raw = $files;
	}

	function prepare_files($dynamic) {
		$photos = [];

		foreach ($this->files_raw as $file) {
			$photos[$file] = new Photo($file, $dynamic);
		}

		$this->photos = $photos;
	}

	function html($dynamic = false) {
		$this->prepare_files($dynamic);

		$html = <<<HTML
<html>
<head>
	<style>
		body {
			display: grid;
			gap: 0.5em;
			grid-template-columns: repeat(auto-fit, 250px);
			grid-auto-flow: dense;
			background-color: black;
		}

		figure {
			margin: 0;
			border: 2px white solid;
					border-radius: 2px;
		}

		figure.has-title a {
			font-style: normal;
		}

		figure a {
			color: white;
			text-decoration: none;
			display: block;
		}

		figure:hover a {
			text-decoration: underline;
		}

		figure a img {
			width: 100%;
		}

		figure figcaption {
			background-color: #555;
			color: #fff;
			font: italic smaller sans-serif;
			text-align: center;
		}

		figure figcaption a {
			padding: 3px;
		}
	</style>
</head>
<body>
HTML;

		foreach ($this->photos as $photo) {
			$html .= $photo->html_thumbnail(250, 250);
		}

		$html .= "</body></html>";

		return $html;
	}
}

if (isset($_REQUEST['image'])) {
	$photo = new Photo($_REQUEST['image']);
	header("Content-Type: image/jpeg");
	if (isset($_REQUEST['w']) and isset($_REQUEST['h'])) {
		$photo->generate_size($_REQUEST['w'], $_REQUEST['h']);
		readfile($photo->path_size($_REQUEST['w'], $_REQUEST['h']));
	} else {
		readfile($photo->path_original());
	}
	die();
} else {
	$options = getopt('o:i:', ['output-directory::', 'input-directory::']);

	$input_directory = '.';
	if (!empty($options['i'])) {
		$input_directory = $options['i'];
	}
	if (!empty($options['input-directory'])) {
		$input_directory = $options['input-directory'];
	}

	$output_directory = null;
	if (!empty($options['o'])) {
		$output_directory = $options['o'];
	}
	if (!empty($options['output-directory'])) {
		$output_directory = $options['output-directory'];
	}

	$gallery = new Gallery();
	$gallery->directory($input_directory);

	if ($output_directory) {
		if (!file_exists($output_directory)) {
			mkdir($output_directory, 0777, true);
		}
		file_put_contents($output_directory."/index.html", $gallery->html());
		foreach ($gallery->photos as $photo) {
			foreach ($photo->files() as $relative_path => $contents) {
				$directory = dirname($output_directory."/".$relative_path);
				if (!file_exists($directory)) {
					mkdir($directory, 0777, true);
				}
				file_put_contents($output_directory."/".$relative_path, $contents);
			}
		}
	} else {
		echo $gallery->html(true);
	}
}
