# php-galerie

php-galerie is a static image gallery generator written in PHP, that fits in one reasonably-sized file without any particular dependencies (and minimal JavaScript).

Called without options, it will generate in-place an `index.html` file from the photos in the current folder, as well as a `.cache` folder containing 250px thumbnails and 1500px versions of the images.

The actual picture files are not modified and only `index.html` and `.cache` are ever written to.

Various options control the output, including recursive traversing of directories, size of thumbnails and images, whether files are copied or symlinked, etc.

<img src="https://user-images.githubusercontent.com/1394204/215459365-a4e01377-2185-420b-8dc2-a468f97da135.png" width="300" />  <img src="https://user-images.githubusercontent.com/1394204/215459362-7b863038-7baf-413c-869a-213115ddfe00.png" width="300" />

The default theme is a neutral (some could call it "depressing") grey, but it's easy enough to style. I'm no graphist so I'll leave this to you.


## Dependencies

PHP >= 7.0 with the `gd` extension and optionally the `exif` one. Might even work on PHP 5 but no guarantees.

What I *can* guarantee is that it's never going to become a bloated beast of a projet with a billion dependencies, precise version requirements, build tools and fancy new technology. It's just something that I want to keep working for the years to come without constant maintenance. Upload your pictures, run the tool and you're good. And even if it ever breaks, the already-generated galleries and their good old plain HTML will still be there.

## Installation

Just checkout the repository, or [download the file](https://github.com/seeschloss/php-galerie/raw/master/php-galerie.php) and put it wherever you like.

## Usage

The `--help` switch should provide mostly up-to-date information:

```bash
php php-galerie.php --help

Generate a static HTML media gallery from directories containing JPEG files.

Options:
  -h, --help                                             Show this help message
  -o <directory>, --output-directory=<directory>         Output directory
                                                             (current directory by default)
  -i <directory>, --input-directory=<directory>          Input directory
                                                             (current directory by default)
  -r, --recursive                                        Follow subdirectories recursively
  -d <depth>, --max-depth=<max-depth>                    Maximum recursive depth, implies --recursive
                                                             0: no limit
                                                             1: no recursivity
                                                             (no limit by default)
  -e, --embed-thumbnails                                 Embed thumbnails in index.html
  -t <width>x<height>, --thumbnail-size=<width>x<height> Resize thumbnails to size
                                                             (250 x 250 by default)
  -s <width>x<height>, --full-size=<width>x<height>      Resize full pictures to size
                                                             (1500 x 1500 by default)
  -c, --crop                                             Crop thumbnails to fill size
  -l, --links                                            Create symlinks rather than copying the image files
  -n <title>, --title=<title>                            Title of the gallery
  -x <Exif field>, --tags=<Exif field>                   Read tags delimited by spaces in this Exif field (off by default, "UserComment" is a good field to use for tagging)
  --script <file/url>                                    Include JavaScript script in the html (inline or as a URL)
  --per-date                                             Create date-based sub-galleries instead of using the original folder structure

```

## Examples

Let's take my `Anvers` folder which contains a number of JPG files, as well as a `.thumbnail.jpg` symlink pointing to the file to be used as thumbnail:

```bash
+[seeschloss@triphasia:Anvers]$ ll | column -x
total 507M
-rw-r--r-- 1 seeschloss 6.3M Aug 29 12:17 IMGP4594.JPG
-rw-r--r-- 1 seeschloss 6.3M Aug 29 12:17 IMGP4619.JPG
...
...
-rw-r--r-- 1 seeschloss 5.1M Aug 29 12:02 IMGP9511.JPG
-rw-r--r-- 1 seeschloss 5.0M Aug 29 12:02 IMGP9513.JPG
lrwxrwxrwx 1 seeschloss   12 Aug 29 12:51 .thumbnail.jpg -> IMGP7910.JPG
```

I can run the following to create a gallery in a folder that's served by Apache:

```bash
php ~/src/php-galerie/php-galerie.php --embed-thumbnails --links --crop --thumbnail-size=250x250 -i ~/photos/Anvers -o ~/http/galerie/
```

This will create a static gallery with thumbnails cropped as 250x250px squares and embeded in the HTML as base64, and full-sized images as symlinks to the originals (since it's the same machine and Apache has access to the original gallery).

Here's the result: https://seos.fr/html/galerie/index.html (for a reason that only I know, most of these images are not actually from Antwerp).
