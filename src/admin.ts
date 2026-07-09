/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Admin settings app (SDD §5.4), mounted into templates/admin.php.
 */
import Vue from 'vue'
import AdminSettings from './components/AdminSettings.vue'

import '@nextcloud/dialogs/style.css'

const View = Vue.extend(AdminSettings)
new View().$mount('#sclrit-admin')
