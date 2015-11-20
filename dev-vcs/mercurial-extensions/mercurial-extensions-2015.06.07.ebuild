# Copyright 1999-2010 Gentoo Foundation
# Distributed under the terms of the GNU General Public License v2
# $Header$
EAPI="4"

DESCRIPTION="Various Mercurial extensions"
HOMEPAGE="http://mercurial.selenic.com/"
SRC_URI="
  https://bitbucket.org/Mekk/mercurial_keyring/raw/fb4371a55e79/mercurial_keyring.py -> fb4371a55e79-mercurial_keyring.py
  https://bitbucket.org/gobell/hg-zipdoc/raw/a2ca45ccfd64/zipdoc.py -> a2ca45ccfd64-zipdoc.py
  https://bitbucket.org/birkenfeld/hgchangelog/raw/bb962d35fd2b/hgchangelog.py -> bb962d35fd2b-hgchangelog.py
  https://bitbucket.org/jinhui/hg-cloc/raw/9a7c5cf25816/cloc.py -> 9a7c5cf25816-cloc.py
  https://bitbucket.org/abuehl/hgext-cifiles/raw/091b6a4b561b/cifiles.py -> 091b6a4b561b-cifiles.py
  https://bitbucket.org/face/timestamp/raw/e85aaaa0a21a/casestop.py -> e85aaaa0a21a-casestop.py
  https://bitbucket.org/peerst/hgcollapse/raw/b42d3b57df3a/hgext/collapse.py -> b42d3b57df3a-collapse.py
  https://bitbucket.org/marmoute/mutable-history/raw/16017e1bb2a1/hgext/evolve.py -> 16017e1bb2a1-evolve.py
  "

RESTRICT="mirror"
LICENSE="GPL-2+ as-is"
SLOT="0"
KEYWORDS="amd64 x86"
IUSE=""

DEPEND=""
RDEPEND="dev-vcs/mercurial"

S="${WORKDIR}"

src_unpack() {
	cd "${DISTDIR}"
	for FILE in *.py
	do
	    cp "${FILE}" "$S/${FILE#*-}"
	done
}

src_install() {
	local dir=/usr/libexec/hgext
	insinto "${dir}"
	doins  *.py
}
