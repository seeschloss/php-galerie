<?php
require __DIR__.'/php-galerie.php';

class UnitTest {
	static $pass = [];
	static $fail = [];
	static $suits = [];

	protected $teardown_actions = [];

	function __destruct() {
		foreach ($this->teardown_actions as $i => $action) {
			$action();
			unset($this->teardown_actions[$i]);
		}
	}

	static function summary() {
		$results = [];

		foreach (self::$suits as $suit) {
			$n_pass = count(self::$pass[$suit]);
			$n_fail = count(self::$fail[$suit]);
			$n_total = $n_pass + $n_fail;

			$results[] = "Test results for {$suit}: {$n_pass}/{$n_total}, fail: {$n_fail}/{$n_total}";
		}

		return implode("\n", $results);
	}

	static function details($test) {
		return "{$test['status']} on function '{$test['function']}': {$test['message']} in file '{$test['context']}'";
	}

	static function fail($result, $message = '') {
		$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2];

		$details = [
			'status' => 'fail',
			'result' => $result,
			'function' => static::class.'::'.$caller['function'],
			'context' => $caller['file'].':'.$caller['line'],
			'message' => $message,
		];

		if (!isset(self::$suits[static::class])) {
			self::$suits[static::class] = static::class;
			self::$fail[static::class] = [];
			self::$pass[static::class] = [];
		}
		self::$fail[static::class][] = $details;

		echo self::details($details)."\n";
	}

	static function pass($result, $message = '') {
		$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2];

		$details = [
			'status' => 'pass',
			'result' => $result,
			'function' => static::class.'::'.$caller['function'],
			'context' => $caller['file'].':'.$caller['line'],
			'message' => $message,
		];

		if (!isset(self::$suits[static::class])) {
			self::$suits[static::class] = static::class;
			self::$fail[static::class] = [];
			self::$pass[static::class] = [];
		}
		self::$pass[static::class][] = $details;
	}

	static function assertTrue($result) {
		if ($result !== true) {
			self::fail($result, 'Expected true, got false');
		} else {
			self::pass($result, 'Expected true, got true');
		}
	}

	static function assertFalse($result) {
		if ($result !== false) {
			self::fail($result, 'Expected false, got true');
		} else {
			self::pass($result, 'Expected false, got false');
		}
	}

	static function assertPattern($pattern, $result) {
		if (preg_match($pattern, $result)) {
			self::pass($result, "Pattern '{$pattern}' matches");
		} else {
			if (mb_strlen($result) > 100) {
				$string = mb_substr($result, 0, 100).'...';
			} else {
				$string = $result;
			}
			$string = str_replace("\n", '\n', $string);
			self::fail($result, "Pattern '{$pattern}' not found in '{$string}'");
		}
	}

	static function assertString($string, $result) {
		if (strpos($result, $string) !== false) {
			self::pass($result, "String '{$string}' found");
		} else {
			if (mb_strlen($result) > 100) {
				$haystack = mb_substr($result, 0, 100).'...';
			} else {
				$haystack = $result;
			}
			$haystack = str_replace("\n", '\n', $haystack);
			self::fail($result, "String '{$string}' not found in '{$haystack}'");
		}
	}

	static function assertEqual($value, $expected) {
		if ($value === $expected) {
			self::pass($expected, sprintf("%s equals %s", json_encode($value), json_encode($expected)));
		} else {
			self::fail($expected, sprintf("%s differs from %s", json_encode($value), json_encode($expected)));
		}
	}

	static function assertNoString($string, $result) {
		if (strpos($result, $string) !== true) {
			self::pass($result, "String '{$string}' not found");
		} else {
			if (mb_strlen($result) > 100) {
				$haystack = mb_substr($result, 0, 100).'...';
			} else {
				$haystack = $result;
			}
			$haystack = str_replace("\n", '\n', $haystack);
			self::fail($result, "String '{$string}' found in '{$haystack}'");
		}
	}

	static function _domElementFromHtml($fragment) {
		if (!preg_match('/^<.*>$/', $fragment)) {
			return null;
		}

		libxml_use_internal_errors(true);

		$element = null;
		$element_doc = new DOMDocument();
		$element_doc->loadHTML($fragment);
		if (strpos($fragment, '<html') === 0) {
			$element = $element_doc->getElementsByTagName('html')[0];
		} else if (strpos($fragment, '<body') === 0) {
			$element = $element_doc->getElementsByTagName('body')[0];
		} else if (count($element_doc->getElementsByTagName('body'))) {
			foreach ($element_doc->getElementsByTagName('body') as $body_node) {
				if ($body_node->childNodes->length == 1) {
					$element = $body_node->childNodes[0];
				}
			}
		} else if (count($element_doc->getElementsByTagName('head'))) {
			foreach ($element_doc->getElementsByTagName('head') as $head_node) {
				if ($head_node->childNodes->length == 1) {
					$element = $head_node->childNodes[0];
				}
			}
		}

		return $element;
	}

	static function _findHtmlElement($searched_element, $document, &$errors = []) {
		$found_elements = $document->getElementsByTagName($searched_element->tagName);
		$matching_elements = [];
		if (!count($found_elements)) {
			$errors[] = "No element found with tag '{$searched_element->tagName}'";
		} else foreach ($found_elements as $found_element) {
			if (count($searched_element->attributes) > 0) {
				foreach ($searched_element->attributes as $attribute) {
					if (!$found_element->hasAttribute($attribute->name)) {
						$errors[] = "Element '{$searched_element->tagName}' found, but does not have attribute '{$attribute->name}'";
					} else if (preg_match('#^/.*/$#', $attribute->value)) {
						if (!preg_match($attribute->value, $found_element->getAttribute($attribute->name))) {
							$errors[] = "Attribute '{$attribute->name}' of element '{$searched_element->tagName}' has value '{$found_element->getAttribute($attribute->name)}', does not match '{$attribute->value}'";
						} else {
							$matching_elements[] = $found_element;
						}
					} else if ($found_element->getAttribute($attribute->name) != $attribute->value) {
						$errors[] = "Attribute '{$attribute->name}' of element '{$searched_element->tagName}' has value '{$found_element->getAttribute($attribute->name)}', not '{$attribute->value}'";
					} else {
						$matching_elements[] = $found_element;
					}
				}
			} else {
				$matching_elements[] = $found_element;
			}
		}

		if ($searched_element->childNodes->length > 0) {
			foreach ($matching_elements as $id => $matching_element) {
				foreach ($searched_element->childNodes as $searched_child) {
					if ($searched_child->nodeName == '#text') {
						if (preg_match('#^/.*/$#', $searched_child->textContent)) {
							if (!preg_match($searched_child->textContent, $matching_element->textContent)) {
								$errors[] = "Element '{$searched_element->tagName}' has text '{$matching_element->textContent}', not '{$searched_element->textContent}'";
								$errors[] = "Element '{$matching_element->tagName}' has value '{$matching_element->textContent}', does not match '{$searched_element->textContent}'";
								unset($matching_elements[$id]);
							}
						} else if ($matching_element->textContent != $searched_child->textContent) {
							$errors[] = "Element '{$searched_element->tagName}'  '{$matching_element->textContent}', not '{$searched_element->textContent}'";
							unset($matching_elements[$id]);
						}
					} else {
						$found_children = self::_findHtmlElement($searched_child, $matching_element, $errors);

						if (!count($found_children)) {
							unset($matching_elements[$id]);
						}
					}
				}
			}
		}

		return $matching_elements;
	}

	static function assertHtmlElement($fragment, $html) {
		if (!preg_match('/^<.*>$/', $fragment)) {
			return self::fail('', "Element '{$fragment}' is not a valid HTML element");
		}

		libxml_use_internal_errors(true);
		$document = new DOMDocument();
		$document->loadHTML($html);

		if (!$searched_element = self::_domElementFromHtml($fragment)) {
			return self::fail('', "Element '{$fragment}' is not a valid HTML element");
		}

		$errors = [];
		$matching_elements = self::_findHtmlElement($searched_element, $document, $errors);
		if (count($matching_elements) == 0) {
			foreach ($errors as $error) {
				return self::fail('', $error);
			}

			self::fail($html, "Element '{$fragment}' not found");
		} else {
			self::pass($html, "Element '{$fragment}' found");
		}
	}

	static function assertNoHtmlElement($fragment, $html) {
		if (!preg_match('/^<.*>$/', $fragment)) {
			return self::fail('', "Element '{$fragment}' is not a valid HTML element");
		}

		libxml_use_internal_errors(true);
		$document = new DOMDocument();
		$document->loadHTML($html);

		if (!$searched_element = self::_domElementFromHtml($fragment)) {
			return self::fail('', "Element '{$fragment}' is not a valid HTML element");
		}

		$errors = [];
		$matching_elements = self::_findHtmlElement($searched_element, $document, $errors);
		if (count($matching_elements) == 0) {
			self::pass($html, "Element '{$fragment}' not found");
		} else {
			self::fail($html, "Element '{$fragment}' found");
		}
	}
}

class test_Gallery extends UnitTest {
	private function tmp_photo($width, $height, $title = "") {
		$im = imagecreatetruecolor($width, $height);
		$text_color = imagecolorallocate($im, 233, 14, 91);
		$maxrnd = rand(10,70);

		for($y=0;$y<$maxrnd;$y++) {
			imagestring($im, 10+$y*2, 50+$y*2, 50+$y*2, 'PLOP', $text_color);
		};


		$filename = $title ? tempnam("/tmp", "gallery.{$title}.jpg") : tempnam("/tmp", "gallery.jpg");

		imagejpeg($im, $filename, 90);

		$this->teardown_actions[] = function() use($filename) {
			unlink($filename);
		};

		$photo = new Photo($filename);
		return $photo;
	}

	function __construct() {
	}

	function test_html() {
		$gallery = new Gallery();
		self::assertString('<!DOCTYPE html>', $gallery->html());
		self::assertHtmlElement('<html class="php-galerie">', $gallery->html());

		self::assertHtmlElement('<div id="header"><h1></h1></div>', $gallery->html());
	}

	function test_html_title() {
		$gallery = new Gallery();
		$gallery->title = "Title";
		self::assertHtmlElement('<div id="header"><h1>Title</h1></div>', $gallery->html());
		self::assertHtmlElement('<title>Title</title>', $gallery->html());
	}

	function test_html_parent() {
		$gallery = new Gallery();
		$gallery->title = "Title";

		$parent_gallery = new Gallery();
		$parent_gallery->url = "https://example.com";

		$gallery->parent = $parent_gallery;

		self::assertHtmlElement('<div id="header"><a href=".."></div>', $gallery->html());
	}

	function test_html_gallery_thumbnail() {
		$gallery = new Gallery();
		$gallery->thumbnail_width = 42;
		$gallery->thumbnail_height = 63;
		$gallery->thumbnail = $this->tmp_photo(800, 600);

		self::assertHtmlElement('<html class="php-galerie" data-thumbnail-src="'.$gallery->thumbnail->url_size(42, 63, false, true).'">', $gallery->html());
	}

	function test_html_gallery_media() {
		$gallery = new Gallery();
		$gallery->thumbnail_width = 42;
		$gallery->thumbnail_height = 63;
		$gallery->embed_thumbnails = true;
		$gallery->media[] = $this->tmp_photo(800, 600, "title1");

		self::assertNoHtmlElement('<figure class="photo">', $gallery->html());
		self::assertHtmlElement('<figure class="has-title photo">', $gallery->html());
		self::assertHtmlElement('<a href="/gallery.title1.*/">', $gallery->html());
		self::assertHtmlElement('<a href="/gallery.title1.*/"><img src="/data:image\/jpeg;base64/" /></a>', $gallery->html());

		$gallery = new Gallery();
		$gallery->thumbnail_width = 42;
		$gallery->thumbnail_height = 63;
		$gallery->embed_thumbnails = true;
		$gallery->media[] = $this->tmp_photo(800, 600);

		self::assertHtmlElement('<figure class="photo">', $gallery->html());
		self::assertNoHtmlElement('<figure class="has-title photo">', $gallery->html());
	}

	function test_html_gallery_css() {
		$gallery = new Gallery();

		self::assertHtmlElement('<style>', $gallery->html());
	}

	function test_html_gallery_javascript() {
		$gallery = new Gallery();

		self::assertHtmlElement('<script>', $gallery->html());
	}
}

class test_Media extends UnitTest {
	function test_media_title() {
		$media = new Media('/tmp/test.jpg');
		$this->assertEqual('test.jpg', $media->title());

		$media = new Media('/tmp/test.Title.jpg');
		$this->assertEqual('Title', $media->title());

		$media = new Media('/tmp/test.Title_with_spaces.jpg');
		$this->assertEqual('Title with spaces', $media->title());

		$media = new Media('/tmp/test.SEOS.FR.jpg');
		$this->assertEqual('SEOS.FR', $media->title());
	}

	function test_media_classes() {
		$media = new Media('/tmp/test.jpg');
		$this->assertEqual(count($media->classes()), 0);

		$media = new Media('/tmp/test.Title.jpg');
		$this->assertEqual(count($media->classes()), 1);
		$this->assertTrue(in_array('has-title', $media->classes()));
	}

	function test_path_original() {
		$media = new Media('/tmp/test.jpg');
		$this->assertEqual($media->path_original(), '/tmp/test.jpg');
	}

	function test_url_original() {
		$media = new Media('/tmp/plop/test.jpg');
		$this->assertEqual($media->url_original(), 'test.jpg');
	}

	function test_html_attributes() {
		$media = new Media('/tmp/plop/test.jpg');
		$this->assertString('class=""', $media->html_attributes());
		$this->assertString('data-title="test.jpg"', $media->html_attributes());
		$this->assertNoString('data-tags', $media->html_attributes());

		$media->tags = ['tag', 'test'];
		$this->assertString('data-tags="tag test"', $media->html_attributes());

		$media = new Media('/tmp/test.Title.jpg');
		$this->assertString('class="has-title"', $media->html_attributes());
		$this->assertString('data-title="Title"', $media->html_attributes());
	}
}

class test_Photo extends UnitTest {
	private function tmp_photo($width, $height, $title = "") {
		$im = imagecreatetruecolor($width, $height);
		$text_color = imagecolorallocate($im, 233, 14, 91);
		$maxrnd = rand(10,70);

		for($y=0;$y<$maxrnd;$y++) {
			imagestring($im, 10+$y*2, 50+$y*2, 50+$y*2, 'PLOP', $text_color);
		};


		$filename = $title ? tempnam("/tmp", "gallery.{$title}.jpg") : tempnam("/tmp", "gallery.jpg");

		imagejpeg($im, $filename, 90);

		$this->teardown_actions[] = function() use($filename) {
			unlink($filename);
		};

		$photo = new Photo($filename);
		return $photo;
	}

	function test_classes() {
		$photo = new Photo('/tmp/test.jpg');
		$this->assertEqual($photo->classes(), ['photo']);

		$photo = new Photo('/tmp/test.Title.jpg');
		$this->assertEqual($photo->classes(), ['has-title', 'photo']);
	}

	function test_url_size() {
		$photo = new Photo('/tmp/test.jpg');
		$this->assertEqual('.cache/w=100,h=100,c,test.jpg', $photo->url_size(100, 100, true, false));

		$photo = new Photo('/tmp/test.jpg');
		$this->assertEqual('.cache/w=150,h=100,test.jpg', $photo->url_size(150, 100, false, false));

		$photo = $this->tmp_photo(800, 600);
		$this->assertString('data:image/jpeg;base64,', $photo->url_size(50, 50, true, true));
		$image_data = base64_decode(substr($photo->url_size(50, 50, true, true), strlen('data:image/jpeg;base64,')));
		$image = imagecreatefromstring($image_data);
		$this->assertEqual(50, imagesx($image));
		$this->assertEqual(50, imagesy($image));

		$photo = $this->tmp_photo(800, 600);
		$this->assertString('data:image/jpeg;base64,', $photo->url_size(50, 50, false, true));
		$image_data = base64_decode(substr($photo->url_size(50, 50, false, true), strlen('data:image/jpeg;base64,')));
		$image = imagecreatefromstring($image_data);
		$this->assertEqual(50, imagesx($image));
		$this->assertEqual(37, imagesy($image));
	}

	function test_generate_size() {
		$photo = $this->tmp_photo(800, 600);
		$image = imagecreatefromstring($photo->generate_size(50, 50, true, true));
		$this->assertEqual(50, imagesx($image));
		$this->assertEqual(50, imagesy($image));

		$photo = $this->tmp_photo(800, 600);
		$image = imagecreatefromstring($photo->generate_size(50, 50, false, true));
		$this->assertEqual(50, imagesx($image));
		$this->assertEqual(37, imagesy($image));
	}

	function test_path_size() {
		$photo = new Photo('/tmp/test.jpg');
		$this->assertEqual('/tmp/.cache/w=100,h=100,c,test.jpg', $photo->path_size(100, 100, true, false));

		$photo = new Photo('/tmp/test.jpg');
		$this->assertEqual('/tmp/.cache/w=150,h=100,test.jpg', $photo->path_size(150, 100, false, false));
	}
}

foreach (get_declared_classes() as $class) {
	if (is_subclass_of($class, 'UnitTest')) {
		$unit_test = new $class();
		foreach (get_class_methods($unit_test) as $method) {
			$reflection = new ReflectionMethod($class, $method);
			if ($reflection->isPublic() and !$reflection->isStatic()) {
				$unit_test->$method();
			}
		}
	}
}
echo UnitTest::summary()."\n";

