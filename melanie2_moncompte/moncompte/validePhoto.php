<?php
/**
 * Plugin Melanie2_Moncompte
 *
 * plugin melanie2_Moncompte pour roundcube
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */


use LibMelanie\Ldap\Ldap as Ldap;
use LibMelanie\Config\Ldap as Config;

class validePhoto {
	
	/**
	 * Autoriser la publication de sa photo
	 */
	static function changePhoto() {
		
		$case_intra = rcube_utils::get_input_value('photo_intra', RCUBE_INPUT_POST);
		$case_ader = rcube_utils::get_input_value('photo_ader', RCUBE_INPUT_POST);

		$info = array();
		if ( !empty($case_intra) && $case_intra == '1') {
			$info['mineqpublicationphotointranet'] = 1;
		} else {
			$info['mineqpublicationphotointranet'] = 0;
		}
		
		if ( !empty($case_ader) && $case_ader == '1' && !empty($case_intra) && $case_intra == '1')
		{
			$info['mineqpublicationphotoader'] = 1;
		} else {
			$info['mineqpublicationphotoader'] = 0;
		}
			
		$user_infos = melanie2::get_user_infos(Moncompte::get_current_user_name());
		
		$user_dn = $user_infos['dn'];
		$password = rcmail::get_instance()->get_user_password();
		
		$auth = Ldap::GetInstance(Config::$MASTER_LDAP)->authenticate($user_dn, $password);
		
		if(Ldap::GetInstance(Config::$MASTER_LDAP)->modify($user_dn, $info)) {
			rcmail::get_instance()->output->show_message(rcmail::get_instance()->gettext('photo_pub_ok', 'melanie2_moncompte'), 'confirmation');
		} else {
			$err = Ldap::GetInstance(Config::$MASTER_LDAP)->getError();
			rcmail::get_instance()->output->show_message(rcmail::get_instance()->gettext('photo_pub_nok', 'melanie2_moncompte') . $err, 'error');
		}
		
	}
		
}


