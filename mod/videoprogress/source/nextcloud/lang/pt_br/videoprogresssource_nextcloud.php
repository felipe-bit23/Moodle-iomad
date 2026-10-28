<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * videoprogresssource_nextcloud.php
 *
 * @package   videoprogresssource_nextcloud
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$string['invalidcontenttype'] = 'O compartilhamento do Nextcloud não é um vídeo. O servidor deve retornar um Content-Type começando com video/ ou uma playlist HLS.';
$string['invalidmedia'] = 'Não foi possível acessar o compartilhamento do Nextcloud ou ele não retornou um arquivo de vídeo.';
$string['invalidurl'] = 'Informe uma URL pública de compartilhamento do Nextcloud no formato https://exemplo.com/s/ShareToken ou https://exemplo.com/index.php/s/ShareToken.';
$string['nextcloudurl'] = 'URL de compartilhamento do Nextcloud';
$string['nextcloudurl_help'] = 'Cole um link público de compartilhamento do Nextcloud, como https://cloud.exemplo.com/s/ShareToken. O Video Progress converte o link em uma URL de download e verifica se o arquivo compartilhado é um vídeo antes de salvar.';
$string['pluginname'] = 'Nextcloud';
$string['privacy:metadata'] = 'O subplugin do Nextcloud não armazena dados pessoais separadamente da atividade Video Progress.';
