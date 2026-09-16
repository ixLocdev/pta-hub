Fonts bundled for the share square (PTK_Share_Image).

  LibreFranklin-Bold.ttf        Libre Franklin 700
  LibreFranklin-ExtraBold.ttf   Libre Franklin 800
  Newsreader-Regular.ttf        Newsreader 400

Both families are licensed under the SIL Open Font License 1.1, which permits
bundling and redistribution -- see OFL-LibreFranklin.txt and OFL-Newsreader.txt.

Source: Google Fonts (fonts.gstatic.com), then subset with pyftsubset to the
Latin range only (U+0020-007E, U+00A0-00FF, plus en/em dashes, curly quotes and
an ellipsis) and stripped of hinting and layout features, because GD's
imagettftext() uses none of them. Full families would have added roughly 1 MB
to a plugin zip that is 218 KB; these three files are about 66 KB together.

Only the three weights the square actually draws with are here. If a future
design needs another weight, subset it the same way rather than dropping in a
full family.
