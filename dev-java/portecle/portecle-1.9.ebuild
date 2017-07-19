# Copyright 1999-2008 Gentoo Foundation
# Distributed under the terms of the GNU General Public License v2
# $Header: $

EAPI="5"

inherit eutils java-pkg-2

DESCRIPTION="Keystore management tool."
SRC_URI="mirror://sourceforge/${PN}/${P}.zip"
HOMEPAGE="http://portecle.sourceforge.net/"

KEYWORDS="~amd64 ~x86"
SLOT="0"
LICENSE="GPL-2"
IUSE=""

DEPEND="app-arch/unzip"
RDEPEND="|| ( >=virtual/jre-1.5 >=virtual/jdk-1.5 )"


src_compile() {
	sed -e  "/^Icon=/s/${PN}/${PN}.ico/" -i "${PN}.desktop"
}

src_install() {

	java-pkg_dojar *.jar
	java-pkg_dolauncher "${PN}" --main net.sf.portecle.FPortecle

	dodoc *.txt

	doicon "${PN}.ico"
	domenu "${PN}.desktop"
}

