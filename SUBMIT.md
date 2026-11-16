# Submitting pingwa to WordPress.org

What goes where: `build/pingwa.zip` is the plugin (review upload), `.wordpress-org/` is the
listing art (SVN `assets/`, uploaded after approval). The zip never contains `.wordpress-org/`.

## 0. Before you submit

1. Register the WordPress.org account `pingwa` (https://login.wordpress.org/register). It
   must match `Contributors: pingwa` in `readme.txt`, and its e-mail must be one you read:
   the review team writes from `plugins@wordpress.org` and a reply is needed within days.
2. `bin/build.sh` builds `build/pingwa/` and `build/pingwa.zip`.
3. Plugin Check on the build, 0 errors expected (`tests/e2e/run.sh` runs it; or install the
   Plugin Check plugin on any WordPress and run it on `pingwa`).
4. `Version` in `pingwa.php`, `PINGWA_VERSION` and `Stable tag` in `readme.txt` all say `0.1.0`.

## 1. Submit the plugin for review

1. Log in as `pingwa` and open https://wordpress.org/plugins/developers/add/.
2. Upload `build/pingwa.zip` and submit. The plugin name is read from the header
   (`pingwa – order and form alerts`); the directory slug is made from it by the reviewers.
3. Wait for the e-mail (usually days to a few weeks). Reply to the same thread, in plain text,
   with what they ask and attach a new zip only if they ask for code changes.
4. Say in the first reply, or in the review thread, that you want the slug **`pingwa`** (the
   reviewers set it; the SVN URL below depends on it).
5. Review notes worth having ready: the plugin talks to one external service (pingwa.dev),
   disclosed in `== External services ==` with its terms and privacy URLs; nothing is sent
   before the user saves a hook URL; no WhatsApp or Meta logos are used (the name has no
   "WhatsApp" in it).

## 2. After approval: the SVN upload

The repository is `https://plugins.svn.wordpress.org/pingwa/` (empty `trunk/`, `tags/`,
`assets/` at first). Log in with the `pingwa` username and its WordPress.org password (or
the SVN password from your profile).

```sh
cd ~/git/pingwa/wp
bin/build.sh                                   # build/pingwa/ = what goes to trunk
svn checkout https://plugins.svn.wordpress.org/pingwa ~/svn-pingwa
cd ~/svn-pingwa

# trunk: the runtime files
rsync -a --delete ~/git/pingwa/wp/build/pingwa/ trunk/

# assets: the listing art, next to trunk (not inside it)
cp ~/git/pingwa/wp/.wordpress-org/*.png assets/

svn add --force trunk assets
svn propset svn:mime-type image/png assets/*.png

# tags/0.1.0: a copy of trunk. readme.txt says "Stable tag: 0.1.0", so this tag must exist.
svn cp trunk tags/0.1.0

svn status                                     # check: pingwa.php, readme.txt, includes/, assets/*.png
svn ci -m "Release 0.1.0" --username pingwa
```

The listing, the icon and the banners show up within about 15 minutes. Check
https://wordpress.org/plugins/pingwa/: the four screenshot captions in `readme.txt`
(`== Screenshots ==`) match `assets/screenshot-1.png` … `screenshot-4.png` by number.

Layout when done:

```
trunk/            pingwa.php readme.txt uninstall.php includes/ assets/join-wp-qr.svg
tags/0.1.0/       the same
assets/           icon-128x128.png icon-256x256.png banner-772x250.png banner-1544x500.png
                  screenshot-1.png … screenshot-4.png
```

(The plugin's own `assets/join-wp-qr.svg` is inside `trunk/`; the SVN `assets/` next to it is
the listing art. They are different folders.)

## 3. Later releases

1. Bump `Version` (pingwa.php), `PINGWA_VERSION` and `Stable tag` (readme.txt); add a
   `= x.y.z =` entry to the changelog and the upgrade notice.
2. `bin/build.sh`, `rsync` into `trunk/`, `svn cp trunk tags/x.y.z`, `svn ci`.
3. Listing art only: change `.wordpress-org/` and commit to `assets/` alone, no new tag.

## Regenerating the art

`.wordpress-org/*.png` are made by `bin/store-assets/` (see its `render.py` header):

```sh
KEEP=1 tests/e2e/run.sh                         # leaves the Docker stack up (pingwa-wp-*)
python3 bin/store-assets/shoot-settings.py      # screenshot-1.png + real/join-crop.png from the real page
python3 bin/store-assets/render.py wp           # icons, banners, screenshots 2-4
docker compose -f tests/e2e/docker-compose.yml down -v   # remove the stack
```
