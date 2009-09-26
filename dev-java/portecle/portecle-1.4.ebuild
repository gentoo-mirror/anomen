# Copyright 1999-2008 Gentoo Foundation
# Distributed under the terms of the GNU General Public License v2
# $Header: $

inherit eutils java-pkg-2

DESCRIPTION="other share file archives."
SRC_URI="mirror://sourceforge/${PN}/${P}.zip"
HOMEPAGE="http://portecle.sourceforge.net/"

KEYWORDS="~amd64 ~x86"
SLOT="0"
LICENSE="GPL-2"
IUSE=""

DEPEND="app-arch/unzip"
RDEPEND="virtual/jre"


#src_unpack() {
#        unpack $FRD_ZIP_FILE || die
#
#        cd ${S}
#        sed -i -e "s!frd.jar!${FRD_INSTALL_DIR}/frd.jar!" frd.sh
#}

src_install() {

	java-pkg_dojar *.jar
	java-pkg_dolauncher ${PN} --main net.sf.portecle.FPortecle

	dodoc *.txt

	doicon portecle.ico
#	make_desktop_entry "${PN}" Portecle "portecle.ico" "Development"
	domenu "portecle.desktop"
}
