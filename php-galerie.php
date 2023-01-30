<?php

class Video extends Media {
	public $thumbnail_file = '';

	function classes() {
		$classes = parent::classes();
		$classes[] = 'video';

		return $classes;
	}

	function html_thumbnail($width, $height, $crop_thumbnail = false, $embed_thumbnail = false, $target_width = null, $target_height = null) {
		$extra = '';
		if (file_exists($this->thumbnail_file)) {
			$extra .= ' poster="'.htmlspecialchars(basename($this->thumbnail_file)).'"';
			$this->files[basename($this->thumbnail_file)] = ['path' => $this->thumbnail_file];
		}

		$html = <<<HTML
	<figure class="{$classes}">
		<a href="{$this->url_original()}"><video preload="none" controls height="{$height}" width="{$width}" src="{$this->url_original()}" $extra></video>
		<figcaption><a href="{$this->url_original()}">{$this->title()}</a></figcaption>
	</figure>

HTML;

		$this->files[basename($this->original_path)] = ['path' => $this->path_original()];

		Log::stderr("%");

		return $html;
	}

	function url_size($width, $height, $crop = false, $embed = false) {
		return $this->url_original();
	}
}

class Photo extends Media {
	public $exif = [];
	public $preview = null;

	function __construct($path) {
		parent::__construct($path);
	}

	function preview_image() {
		// This function reads the APP2 segment in the JPEG and
		// checks if it contains MPF data. MPF is used to store additional
		// preview images, including hopefuly a "reasonably-sized one".
		// cf. http://fileformats.archiveteam.org/wiki/JPEG
		// and https://exiftool.org/TagNames/MPF.html
		if ($this->preview === null) {
			$data = file_get_contents($this->original_path);
			$app2_offset = strpos($data, "\xFF\xE2");
			if ($app2_offset and substr($data, $app2_offset + 4, 3) == 'MPF') {
				$app2_length = unpack("vlength", substr($data, $app2_offset + 2, 2))['length'];
				$mpf_data = substr($data, $app2_offset + 4 + 4, $app2_length);
				$mpf = new MPF($mpf_data);
				foreach ($mpf->images as $image) {
					if ($image['type'] == 0x10002) {
						// "Large Thumbnail"
						$preview = substr($data, $image['start'] + $app2_offset + 4 + 4, $image['length']);
						if (substr($preview, 0, 3) == "\xff\xd8\xff") {
							$this->preview = $preview;
							return $this->preview;
						}
					}
				}
			}
		}

		return null;
	}

	function read_exif() {
		if (empty($this->exif)) {
			$this->exif = exif_read_data($this->original_path);
		}
	}

	function read_tags($field) {
		$tags = [];
		$this->read_exif();
		if (!empty($this->exif['COMPUTED'][$field])) foreach (explode(' ', $this->exif['COMPUTED'][$field]) as $tag) {
			$tag = trim($tag);
			$tags[$tag] = $tag;
		}
		$this->tags = array_keys($tags);
	}

	function html_thumbnail($width, $height, $crop_thumbnail = false, $embed_thumbnail = false, $target_width = null, $target_height = null) {
		$html = <<<HTML
	<figure {$this->html_attributes()}>
		<a href="{$this->url_size($target_width, $target_height)}"><img src="{$this->url_size($width, $height, $crop_thumbnail, $embed_thumbnail)}" /></a>
		<figcaption><a href="{$this->url_size($target_width, $target_height)}">{$this->title()}</a></figcaption>
	</figure>

HTML;

		$this->files[basename($this->original_path)] = ['path' => $this->path_original()];

		$this->generate_size($target_width, $target_height);
		$this->files[str_replace(dirname($this->original_path)."/", "", $this->path_size($target_width, $target_height))] = ['path' => $this->path_size($target_width, $target_height)];

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

	function generate_size($width, $height, $crop = false) {
		if (!file_exists($this->path_size($width, $height, $crop)) or filemtime($this->path_size($width, $height, $crop)) < filemtime($this->original_path)) {
			$cache_directory = dirname($this->original_path)."/.cache";
			if (!file_exists($cache_directory)) {
				mkdir($cache_directory);
			}

			$data_orig = null;
			$width = (int)$width;
			$height = (int)$height;

			$width_dest = (int)$width;
			$height_dest = (int)$height;

			$r = null;

			$exif_thumbnail_width = 0;
			$exif_thumbnail_height = 0;
			$exif_thumbnail_type = '';
			$exif_thumbnail = exif_thumbnail($this->original_path, $exif_thumbnail_width, $exif_thumbnail_height, $exif_thumbnail_type);
			if ($exif_thumbnail and $exif_thumbnail_width >= $width_dest and $exif_thumbnail_height >= $height_dest) {
				$r = imagecreatefromstring($exif_thumbnail);
				$data_orig = $exif_thumbnail;
				list($width_orig, $height_orig) = getimagesize($this->original_path);
				// Thumbnails aren't always at the same size, and might be padded with borders for some stupid reason
				// let's crop them to the original ratio

				$original_ratio = $height_orig/$width_orig;
				$thumbnail_ratio = $exif_thumbnail_height/$exif_thumbnail_width;

				if ($original_ratio > $thumbnail_ratio) {
					$cropped_thumbnail_width = $exif_thumbnail_width;
					$cropped_thumbnail_height = floor($exif_thumbnail_width * $original_ratio);

					if ($cropped_thumbnail_width >= $width_dest and $cropped_thumbnail_height >= $height_dest) {
						$r_resized = imagecreatetruecolor($cropped_thumbnail_width, $cropped_thumbnail_height);
						imagecopy($r_resized, $r, 0, 0, ($exif_thumbnail_width - $cropped_thumbnail_width)/2, 0, $cropped_thumbnail_width, $cropped_thumbnail_height);
						$r = $r_resized;

						$width_orig = $cropped_thumbnail_width;
						$height_orig = $cropped_thumbnail_height;
					} else {
						// Looks like it wasn't big enough after cropping...
						$r = null;
					}
				} else if ($original_ratio < $thumbnail_ratio) {
					$cropped_thumbnail_width = $exif_thumbnail_width;
					$cropped_thumbnail_height = floor($exif_thumbnail_width * $original_ratio);

					if ($cropped_thumbnail_width >= $width_dest and $cropped_thumbnail_height >= $height_dest) {
						$r_resized = imagecreatetruecolor($cropped_thumbnail_width, $cropped_thumbnail_height);
						imagecopy($r_resized, $r, 0, 0, 0, ($exif_thumbnail_height - $cropped_thumbnail_height)/2, $cropped_thumbnail_width, $cropped_thumbnail_height);
						$r = $r_resized;

						$width_orig = $cropped_thumbnail_width;
						$height_orig = $cropped_thumbnail_height;
					} else {
						// Looks like it wasn't big enough after cropping...
						$r = null;
					}
				}
			}

			if (!$r) {
				$this->read_exif();
				if (isset($this->exif['PreviewImageSize']) and count($this->exif['PreviewImageSize']) == 2) {
					$preview_image_size_width = $this->exif['PreviewImageSize'][1];
					$preview_image_size_height = $this->exif['PreviewImageSize'][0];
					if ($preview_image_size_width >= $width_dest and $preview_image_size_height >= $height_dest) {
						$preview_image = $this->preview_image();
						if ($preview_image) {
							$data_orig = $preview_image;
							$width_orig = $preview_image_size_width;
							$height_orig = $preview_image_size_height;
							$r = imagecreatefromstring($preview_image);
						}
					}
				}
			}

			if (!$r) {
				$data_orig = file_get_contents($this->original_path);;
				$r = imagecreatefromjpeg($this->original_path);
				list($width_orig, $height_orig) = getimagesize($this->original_path);
			}

			if (!$r) {
				return null;
			}

			$this->read_exif();
			if (isset($this->exif['Orientation'])) switch ($this->exif['Orientation']) {
				case 1:
					// Nothing to do
					break;
				case 3:
					imagesetinterpolation($r,  IMG_NEAREST_NEIGHBOUR);
					$r = imagerotate($r, 180, 0);
					$data_orig = null;
					break;
				case 6:
					imagesetinterpolation($r,  IMG_NEAREST_NEIGHBOUR);
					$r = imagerotate($r, 270, 0);
					$data_orig = null;

					$width_orig_2 = $width_orig;
					$width_orig = $height_orig;
					$height_orig = $width_orig_2;

					$width_dest_2 = $width_dest;
					$width_dest = $height_dest;
					$height_dest = $width_dest_2;

					break;
				case 8:
					imagesetinterpolation($r,  IMG_NEAREST_NEIGHBOUR);
					$r = imagerotate($r, 90, 0);
					$data_orig = null;

					$width_orig_2 = $width_orig;
					$width_orig = $height_orig;
					$height_orig = $width_orig_2;

					$width_dest_2 = $width_dest;
					$width_dest = $height_dest;
					$height_dest = $width_dest_2;
					break;
			}

			if ($crop) {
				$smallest_edge = min($width_orig, $height_orig);

				if ($r_resized = imagecreatetruecolor($width_dest, $height_dest)) {
					imagecopyresampled($r_resized, $r, 0, 0, ($width_orig - $smallest_edge)/2, ($height_orig - $smallest_edge)/2, $width_dest, $height_dest, $smallest_edge, $smallest_edge);
					imagejpeg($r_resized, $this->path_size($width, $height, $crop), 95);
				} else {
					return null;
				}
			} else {
				$ratio_orig = $width_orig/$height_orig;

				$width_proportional = $width_dest;
				$height_proportional = $height_dest;

				if ($width_dest/$height_dest > $ratio_orig) {
					$width_proportional = floor($height_dest * $ratio_orig);
				} else {
					$height_proportional = floor($width_dest / $ratio_orig);
				}

				if ($width_proportional != $width_orig or $height_proportional != $height_orig or !$data_orig) {
					if ($r_resized = imagecreatetruecolor($width_proportional, $height_proportional)) {
						imagecopyresampled($r_resized, $r, 0, 0, 0, 0, $width_proportional, $height_proportional, $width_orig, $height_orig);
						imagejpeg($r_resized, $this->path_size($width, $height, $crop), 95);
					} else {
						return null;
					}
				} else {
					file_put_contents($this->path_size($width, $height, $crop), $data_orig);
				}
			}

			Log::stderr($width > 400 ? '+' : '%');
		}

		return file_get_contents($this->path_size($width, $height, $crop));
	}

	function path_size($width, $height, $crop = false) {
		$cache_directory = dirname($this->original_path)."/.cache";

		if (!file_exists($cache_directory)) {
			mkdir($cache_directory, 0777, true);
			file_put_contents($cache_directory.'/CACHEDIR.TAG', 'Signature: 8a477f597d28d172789f06886806bc55');
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

	function date() {
		$this->read_exif();
		if (isset($this->exif['DateTime'])) {
			return strtotime($this->exif['DateTime']);
		} else if (isset($this->exif['DateTimeOriginal'])) {
			return strtotime($this->exif['DateTimeOriginal']);
		} else if (isset($this->exif['DateTimeDigitized'])) {
			return strtotime($this->exif['DateTimeDigitized']);
		}

		return parent::date();
	}
}

class Media {
	public $original_path = "";

	public $files = [];
	public $tags = [];

	function __construct($path) {
		$this->original_path = $path;
	}

	function html_attributes() {
		$attributes = [
			'class' => implode(' ', $this->classes()),
			'data-title' => $this->title(),
			'data-original' => $this->url_original(),
		];

		if (!empty($this->tags)) {
			$attributes['data-tags'] = implode(' ', $this->tags);
		}

		$html = "";
		foreach ($attributes as $attribute => $value) {
			$html .= $attribute.'="'.$value.'"';
		}
		return $html;
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

	function date() {
		return filemtime($this->original_path);
	}
}

class MPF {
	private $data;

	public $images = [];

	function __construct($data) {
		$this->data = $data;

		$this->parse();
	}

	function parse() {
		$header_data = substr($this->data, 0, 4);
		if (strlen($header_data) < 4) {
			return false;
		}

		$header = @unpack('c2chars/vTIFF_ID', $header_data);

		// 0x2a is TIFF's magic number
		if (!is_array($header) or $header['TIFF_ID'] != 0x2a) {
			return false;
		}

		$little_endian = true;

		if ($header['chars1'] == 0x49 and $header['chars2'] == 0x49) {
			// Little endian
			$little_endian = true;
		} else if ($header['chars1'] == 0x4d and $header['chars2'] == 0x4d) {
			// Big endian
			$little_endian = false;
		} else {
			return false;
		}

		$bytes = substr($this->data, 4, 4);
		$ifd_offset = unpack($little_endian ? 'VIFDoffset' : 'NIFDoffset', $bytes)['IFDoffset'];

		$data = substr($this->data, $ifd_offset, 2);
		$ifd_entries = unpack($little_endian ? 'vcount' : 'ncount', $data)['count'];

		for ($i = 0; $i < $ifd_entries; $i++) {
			$entry_offset = $ifd_offset + 2 + ($i * 12);

			$data = substr($this->data, $entry_offset, 12);
			$data = unpack($little_endian ? 'vtag/vtype/Vcount/Voffset' : 'ntag/ntype/Ncount/Noffset', $data);

			switch ($data['tag']) {
				case 0xb000:
					$this->mpf_version = substr($this->data, $entry_offset + 12, $data['count']);
					break;
				case 0xb001:
					$this->number_of_images = $data['offset'];
					break;
				case 0xb002:
					$this->image_list_data = substr($this->data, $data['offset'], $data['count']);
					break;
				default:
					break;
			}
		}

		for ($i = 0; $i < $this->number_of_images; $i++) {
			$offset = $i * 16;
			$image_info_data = unpack('Vdata', substr($this->image_list_data, $offset, 4))['data'];
			$image_flags = $image_info_data >> 27 & 0x1f;
			$image_format = $image_info_data >> 24 & 0x7;
			$image_type = $image_info_data & 0xffffff;

			$image_length = unpack('Vdata', substr($this->image_list_data, $offset + 4, 4))['data'];
			$image_start = unpack('Vdata', substr($this->image_list_data, $offset + 8, 4))['data'];
			$dependent_image_1_entry_number = unpack('vdata', substr($this->image_list_data, $offset + 12, 2))['data'];
			$dependent_image_2_entry_number = unpack('vdata', substr($this->image_list_data, $offset + 14, 2))['data'];

			$this->images[] = [
				'type' => $image_type,
				'start' => $image_start,
				'length' => $image_length,
			];
		}
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
	public $use_symlinks = false;
	public $thumbnail_width = 250;
	public $thumbnail_height = 250;
	public $tags_field = "";
	public $script = "";
	public $image_width = 1500;
	public $image_height = 1500;
	public $per_date = false;

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
				if ($this->tags_field) {
					$media[$file]->read_tags($this->tags_field);
				}
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
							$gallery->use_symlinks = $this->use_symlinks;
							$gallery->thumbnail_width = $this->thumbnail_width;
							$gallery->thumbnail_height = $this->thumbnail_height;
							$gallery->tags_field = $this->tags_field;
							$gallery->script = $this->script;
							$gallery->image_width = $this->image_width;
							$gallery->image_height = $this->image_height;
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

	function css() {
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

		figure.photo, figure.video, figure.gallery {
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
			display: grid;
			grid-template-rows: 100%;
			position: fixed;
			width: 100%;
			height: 100%;
			background-color: rgba(0, 0, 0, 0.9);
		}

		#popup .image-wrapper {
			display: grid;
			justify-items: center;
			align-items: center;
			margin: 0;
			max-width: 100%;
			height: 100%;
		}

		#popup a {
			max-width: 100%;
			height: 100%;
		}

		#popup img {
			max-width: 100%;
			max-height: 100%;
		}

		#popup #prev, #popup #next {
			opacity: 0.2;
			cursor: pointer;
			position: absolute;
			top: 50%;
			height: 0px;
			line-height: 0;
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
			content: "◄";
		}

		#popup #next:before {
			content: "►";
		}

		#popup #next {
			justify-items: end;
		}
CSS;

		if ($this->crop_thumbnails) {
			$css .= <<<CSS
		figure.photo > a img, figure.gallery > a img {
			width: 100%;
		}
		figure.video > a video {
			width: 100%;
		}
CSS;
		} else {
			$css .= <<<CSS
		figure.photo > a img, figure.gallery > a img {
			max-width: 100%;
		}
		figure.video > a video {
			max-width: 100%;
		}
CSS;
		}

		return $css;
	}

	function javascript() {
		$js = <<<JS
	<script>
		function bodyKeyDownHandler(e) {
			switch (e.keyCode) {
				case 27:
					hidePhoto(document.body.querySelector('#popup'));
					break;
				case 39:
					var currentPhoto = document.body.querySelector('.popup-displayed');
					hidePhoto(document.body.querySelector('#popup'));
					if (currentPhoto) {
						showNextPhoto(currentPhoto);
					}
					break;
				case 37:
					var currentPhoto = document.body.querySelector('.popup-displayed');
					hidePhoto(document.body.querySelector('#popup'));
					if (currentPhoto) {
						showPreviousPhoto(currentPhoto);
					}
					break;
			}
		}

		function hidePhoto(div) {
			document.body.removeChild(div);
			document.body.removeEventListener('keydown', bodyKeyDownHandler);
			document.body.querySelector('.popup-displayed').classList.remove('popup-displayed');
		}

		function showPreviousPhoto(currentPhoto) {
			if (currentPhoto.parentElement.previousElementSibling.classList.contains('photo')) {
				var previousPhoto = getPreviousPhoto(currentPhoto);
				if (previousPhoto) {
					showPhoto(previousPhoto);
				}
			}
		}

		function showNextPhoto(currentPhoto) {
			if (currentPhoto.parentElement.nextElementSibling.classList.contains('photo')) {
				var nextPhoto = getNextPhoto(currentPhoto);
				if (nextPhoto) {
					showPhoto(nextPhoto);
				}
			}
		}

		function getPreviousPhoto(a_element) {
			return a_element.parentElement.previousElementSibling.firstElementChild;
		}

		function getNextPhoto(a_element) {
			return a_element.parentElement.nextElementSibling.firstElementChild;
		}

		function showPhoto(a_element) {
			a_element.classList.add('popup-displayed');

			var link_preload_next = document.createElement('link');
			link_preload_next.href = getNextPhoto(a_element);
			link_preload_next.rel = "preload";
			link_preload_next.as = "image";

			var link_preload_previous = document.createElement('link');
			link_preload_previous.href = getPreviousPhoto(a_element);
			link_preload_previous.rel = "preload";
			link_preload_previous.as = "image";

			var div = document.createElement('div');
			div.id = "popup";
			var image_wrapper = document.createElement('figure');
			image_wrapper.className = 'image-wrapper';

			var title = document.createElement('figcaption');
			title.className = 'title';
			if (a_element.parentElement.dataset['title'] !== undefined) {
				title.innerHTML = a_element.parentElement.dataset['title'];
			}
			image_wrapper.appendChild(title);

			var a = document.createElement('a');
			if (a_element.parentElement.dataset['original']) {
				a.href = a_element.parentElement.dataset['original'];
			}
			var img = document.createElement('img');
			img.src = a_element.href;
			var next = document.createElement('div');
			next.id = "next";
			next.class = "arrow";
			var prev = document.createElement('div');
			prev.id = "prev";
			prev.class = "arrow";
			a.appendChild(img);
			image_wrapper.appendChild(a);
			div.appendChild(image_wrapper);
			div.appendChild(prev);
			div.appendChild(next);
			div.appendChild(link_preload_next);
			div.appendChild(link_preload_previous);
			document.body.appendChild(div);
			document.body.addEventListener('keydown', bodyKeyDownHandler);

			var tags = document.createElement('figcaption');
			tags.className = 'tags';
			if (a_element.parentElement.dataset['tags'] !== undefined) {
				tags.innerHTML = a_element.parentElement.dataset['tags'];
			}
			image_wrapper.appendChild(tags);

			a.onclick = function(e) {
				e.stopPropagation();
			};

			div.onclick = function(e) {
				e.preventDefault();
				e.stopPropagation();
				hidePhoto(div);
			};

			prev.onclick = function(e) {
				e.preventDefault();
				e.stopPropagation();
				hidePhoto(div);
				var prevElement = a_element.parentElement.previousElementSibling;
				if (prevElement) {
					var prevPhoto = prevElement.querySelector('a');
					if (prevPhoto) {
						showPhoto(prevPhoto);
					}
				}
			};

			next.onclick = function(e) {
				e.preventDefault();
				e.stopPropagation();
				hidePhoto(div);
				var nextElement = a_element.parentElement.nextElementSibling;
				if (nextElement) {
					var nextPhoto = nextElement.querySelector('a');
					if (nextPhoto) {
						showPhoto(nextPhoto);
					}
				}
			};
		}

		var photos = document.querySelectorAll('.photo a');
		for (var i in photos) {
			photos[i].onclick = function(e) {
				e.preventDefault();
				e.stopPropagation();
				showPhoto(this);
			};
		}
	</script>
JS;

	if ($this->script) {
		if (file_exists($this->script)) {
			$js_content = file_get_contents($this->script);
			$js .= <<<HTML
	<script>
		{$js_content}
	</script>
HTML;
		} else {
			$js .= <<<HTML
	<script src="{$this->script}"></script>
HTML;
		}
	}

		return $js;
	}

	function html() {
		$link_parent = "";
		if ($this->parent) {
			$this->parent->url = '..';
			$link_parent = $this->parent->html_thumbnail($this->thumbnail_width, $this->thumbnail_height);
		}

		$html = <<<HTML
<!DOCTYPE html>
<html class="php-galerie"
	data-thumbnail-src="{$this->thumbnail_base64()}"
	data-thumbnail-height="{$this->thumbnail_height}"
	data-thumbnail-width="{$this->thumbnail_width}"
	data-image-height="{$this->image_height}"
	data-image-width="{$this->image_width}"
	data-embed-thumbnails="{$this->embed_thumbnails}"
	data-crop-thumbnails="{$this->crop_thumbnails}"
	data-use-symlinks="{$this->use_symlinks}"
	data-tags-field="{$this->tags_field}"
	>
<head>
	<title>{$this->title}</title>
	<meta charset="utf-8" />
	<style>
		{$this->css()}
	</style>
</head>
<body>
	<div id="header">{$link_parent}<h1>{$this->title}</h1></div>
HTML;

		if ($this->per_date and !$this->parent) {
			// If we're creating a new date-based structure, then let's merge all
			// found media, and recreate 'virtual' galleries based on dates.

			$all_media = $this->media;
			foreach ($this->galleries as $gallery) {
				$all_media += $gallery->media;
			}
			$this->galleries = [];

			$media_per_date = [];
			foreach ($all_media as $media) {
				$date = strtotime("today midnight", $media->date());
				if (!isset($media_per_date[$date])) {
					$media_per_date[$date] = [];
				}
				$media_per_date[$date][] = $media;
			}

			ksort($media_per_date, SORT_NUMERIC);

			foreach ($media_per_date as $date => $media) {
				$gallery = new Gallery();
				$gallery->parent = $this;
				$gallery->embed_thumbnails = $this->embed_thumbnails;
				$gallery->crop_thumbnails = $this->crop_thumbnails;
				$gallery->use_symlinks = $this->use_symlinks;
				$gallery->thumbnail_width = $this->thumbnail_width;
				$gallery->thumbnail_height = $this->thumbnail_height;
				$gallery->tags_field = $this->tags_field;
				$gallery->script = $this->script;
				$gallery->image_width = $this->image_width;
				$gallery->image_height = $this->image_height;

				$gallery->media = $media;
				$gallery->url = date('Y-m-d', $date);
				$gallery->title = date('Y-m-d', $date);
				$gallery->thumbnail = $media[0];

				$this->galleries[] = $gallery;
				$html .= $gallery->html_thumbnail($this->thumbnail_width, $this->thumbnail_height);
			}
		} else {
			foreach ($this->galleries as $gallery) {
				Log::stderr("#");
				$html .= $gallery->html_thumbnail($this->thumbnail_width, $this->thumbnail_height);
			}

			foreach ($this->media as $media) {
				$html .= $media->html_thumbnail($this->thumbnail_width, $this->thumbnail_height, $this->crop_thumbnails, $this->embed_thumbnails, $this->image_width, $this->image_height);
			}
		}

		$html .= $this->javascript();

		$html .= <<<HTML
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
		Log::stderr("Writing gallery '{$output_directory}': ");
		file_put_contents($output_directory."/index.html", $this->html());
		Log::stderr(".");
		foreach ($this->media as $media) {
			foreach ($media->files as $relative_path => $file) {
				$directory = dirname($output_directory."/".$relative_path);
				if (!file_exists($directory)) {
					mkdir($directory, 0777, true);
				}
				$file_output_path = $output_directory."/".$relative_path;
				if (file_exists($output_directory."/".$relative_path) or ($this->use_symlinks and !is_link($file_output_path))) {
					if (!file_exists($file_output_path) or filemtime($media->path_original()) > filemtime($file_output_path) or ($this->use_symlinks and !is_link($file_output_path))) {
						if (isset($file['path']) and file_exists($file['path'])) {
							if ($this->use_symlinks) {
								if (file_exists($file_output_path)) {
									unlink($file_output_path);
								}
								symlink(realpath($file['path']), $file_output_path);
							} else {
								copy($file['path'], $file_output_path);
							}
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
						if ($this->use_symlinks) {
							if (file_exists($file_output_path)) {
								unlink($file_output_path);
							}
							symlink(realpath($file['path']), $file_output_path);
						} else {
							copy($file['path'], $file_output_path);
						}
						Log::stderr("+");
					} else if (isset($file['data'])) {
						file_put_contents($file_output_path, $file['data']);
						Log::stderr("+");
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

function check_extensions() {
	if (!function_exists('exif_thumbnail')) {
		function exif_thumbnail() {
			return null;
		}
	}

	if (!function_exists('exif_read_data')) {
		function exif_read_data() {
			return [];
		}
	}
}

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
	'full-size' => [
		'short' => 's:',
		'long' => 'full-size::',
		'description' => ['-s <width>x<height>, --full-size=<width>x<height>', 'Resize full pictures to size', '(1500 x 1500 by default)'],
	],
	'crop' => [
		'short' => 'c',
		'long' => 'crop',
		'description' => ['-c, --crop', 'Crop thumbnails to fill size'],
	],
	'symlinks' => [
		'short' => 'l',
		'long' => 'links',
		'description' => ['-l, --links', 'Create symlinks rather than copying the image files'],
	],
	'title' => [
		'short' => 'n:',
		'long' => 'title::',
		'description' => ['-n <title>, --title=<title>', 'Title of the gallery'],
	],
	'tags' => [
		'short' => 'x:',
		'long' => 'tags::',
		'description' => ['-x <Exif field>, --tags=<Exif field>', 'Read tags delimited by spaces in this Exif field (off by default, "UserComment" is a good field to use for tagging)'],
	],
	'script' => [
		'long' => 'script::',
		'description' => ['--script <file/url>', 'Include JavaScript script in the html (inline or as a URL)'],
	],
	'per-date' => [
		'long' => 'per-date',
		'description' => ['--per-date', 'Create date-based sub-galleries instead of using the original folder structure'],
	],
];

$short_options = "";
$long_options = [];
foreach ($options as $option) {
	if (!empty($option['short'])) {
		$short_options .= $option['short'];
	}
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

$use_symlinks = false;
if (isset($cmdline_options['l']) or isset($cmdline_options['links'])) {
	$use_symlinks = true;
}

$thumbnail_size = "250x250";
if (isset($cmdline_options['t'])) {
	$thumbnail_size = $cmdline_options['t'];
}
if (isset($cmdline_options['thumbnail-size'])) {
	$thumbnail_size = $cmdline_options['thumbnail-size'];
}
if (strpos($thumbnail_size, 'x') === false) {
	help_message($options);
	exit(1);
}
list($thumbnail_width, $thumbnail_height) = explode('x', $thumbnail_size);

$title = null;
if (isset($cmdline_options['n'])) {
	$title = $cmdline_options['n'];
}
if (isset($cmdline_options['title'])) {
	$title = $cmdline_options['title'];
}

$tags_field = null;
if (isset($cmdline_options['x'])) {
	$tags_field = $cmdline_options['x'];
}
if (isset($cmdline_options['tags'])) {
	$tags_field = $cmdline_options['tags'];
}

$script = null;
if (isset($cmdline_options['script'])) {
	$script = $cmdline_options['script'];
}

if ((int)$thumbnail_width != $thumbnail_width or (int)$thumbnail_height != $thumbnail_height or $thumbnail_width == 0 or $thumbnail_height == 0) {
	help_message($options);
	exit(1);
}

$full_size = "1500x1500";
if (isset($cmdline_options['s'])) {
	$full_size = $cmdline_options['s'];
}
if (isset($cmdline_options['full-size'])) {
	$full_size = $cmdline_options['full-size'];
}

if (strpos($full_size, 'x') === false) {
	help_message($options);
	exit(1);
}

list($full_width, $full_height) = explode('x', $full_size);

if ((int)$full_width != $full_width or (int)$full_height != $full_height or $full_width == 0 or $full_height == 0) {
	help_message($options);
	exit(1);
}

$per_date = false;
if (isset($cmdline_options['per-date'])) {
	$per_date = true;
}

check_extensions();

$gallery = new Gallery();
$gallery->embed_thumbnails = $embed_thumbnails;
$gallery->crop_thumbnails = $crop_thumbnails;
$gallery->use_symlinks = $use_symlinks;
$gallery->thumbnail_width = $thumbnail_width;
$gallery->thumbnail_height = $thumbnail_height;
$gallery->tags_field = $tags_field;
$gallery->script = $script;
$gallery->image_width = $full_width;
$gallery->image_height = $full_height;
$gallery->per_date = $per_date;
$gallery->read_directory($input_directory, $recursive, $max_depth);
if ($title) {
	$gallery->title = $title;
}

if ($output_directory) {
	Log::stderr(<<<EOT
.	gallery index
#	gallery thumbnail
%	image thumbnail
+	image full resolution
=	image already in cache


EOT
	);
	$gallery->write($output_directory, $recursive, $max_depth);
}
