<?php

/**
 * Tabler Icons — https://github.com/tabler/tabler-icons
 * MIT License
 *
 * Copyright (c) 2020-2026 Paweł Kuna
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

function admin_icon(string $name): string
{
    return '<svg class="admin-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="#admin-icon-' . e($name) . '"></use></svg>';
}

function render_admin_icons(): void
{
    ?>
    <svg class="hidden" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
        <symbol id="admin-icon-copy" viewBox="0 0 24 24">
            <path d="M7 7m0 2a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2z" />
            <path d="M17 7v-2a2 2 0 0 0 -2 -2h-10a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h2" />
        </symbol>
        <symbol id="admin-icon-shield-check" viewBox="0 0 24 24">
            <path d="M12 3a12 12 0 0 0 8.5 3c.5 6 -2.5 10 -8.5 14c-6 -4 -9 -8 -8.5 -14a12 12 0 0 0 8.5 -3" />
            <path d="m9 12 2 2 4 -4" />
        </symbol>
        <symbol id="admin-icon-filter" viewBox="0 0 24 24">
            <path d="M5.5 5h13a1 1 0 0 1 .5 1.5l-5 5.5v7l-4 -3v-4l-5 -5.5a1 1 0 0 1 .5 -1.5" />
        </symbol>
        <symbol id="admin-icon-refresh" viewBox="0 0 24 24">
            <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
            <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
        </symbol>
        <symbol id="admin-icon-layout-dashboard" viewBox="0 0 24 24">
            <path d="M5 4h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1" />
            <path d="M5 16h4a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1" />
            <path d="M15 12h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1" />
            <path d="M15 4h4a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1" />
        </symbol>
        <symbol id="admin-icon-bed" viewBox="0 0 24 24">
            <path d="M5 9a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
            <path d="M22 17v-3h-20" />
            <path d="M2 8v9" />
            <path d="M12 14h10v-2a3 3 0 0 0 -3 -3h-7v5" />
        </symbol>
        <symbol id="admin-icon-key" viewBox="0 0 24 24">
            <path d="M16.555 3.843l3.602 3.602a2.877 2.877 0 0 1 0 4.069l-2.643 2.643a2.877 2.877 0 0 1 -4.069 0l-.301 -.301l-6.558 6.558a2 2 0 0 1 -1.239 .578l-.175 .008h-1.172a1 1 0 0 1 -.993 -.883l-.007 -.117v-1.172a2 2 0 0 1 .467 -1.284l.119 -.13l.414 -.414h2v-2h2v-2l2.144 -2.144l-.301 -.301a2.877 2.877 0 0 1 0 -4.069l2.643 -2.643a2.877 2.877 0 0 1 4.069 0" />
            <path d="M15 9h.01" />
        </symbol>
        <symbol id="admin-icon-home" viewBox="0 0 24 24">
            <path d="M5 12l-2 0l9 -9l9 9l-2 0" />
            <path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7" />
            <path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6" />
        </symbol>
        <symbol id="admin-icon-calendar" viewBox="0 0 24 24">
            <rect x="3" y="5" width="18" height="16" rx="2" />
            <path d="M16 3v4M8 3v4M3 11h18M8 15h2m4 0h2m-8 3h2" />
        </symbol>
        <symbol id="admin-icon-user" viewBox="0 0 24 24">
            <circle cx="12" cy="8" r="4" /><path d="M5 21v-2a7 7 0 0 1 14 0v2" />
        </symbol>
        <symbol id="admin-icon-phone" viewBox="0 0 24 24">
            <path d="M5 3h4l2 5-3 2a15 15 0 0 0 6 6l2-3 5 2v4a2 2 0 0 1-2 2A18 18 0 0 1 3 5a2 2 0 0 1 2-2Z" />
        </symbol>
        <symbol id="admin-icon-message-circle" viewBox="0 0 24 24">
            <path d="M4 19l-1 3 5-2a9 9 0 1 0-4-4l-1 6" /><path d="M8 11h8m-8 4h5" />
        </symbol>
        <symbol id="admin-icon-wallet" viewBox="0 0 24 24">
            <path d="M20 8V5a2 2 0 0 0-2-2H6a3 3 0 0 0 0 6h14v12H6a3 3 0 0 1-3-3V6m17 7h-5v4h5m-3-2h.01" />
        </symbol>
        <symbol id="admin-icon-check" viewBox="0 0 24 24">
            <path d="m5 12 4 4L19 6" />
        </symbol>
        <symbol id="admin-icon-plus" viewBox="0 0 24 24">
            <path d="M12 5l0 14" />
            <path d="M5 12l14 0" />
        </symbol>
        <symbol id="admin-icon-pencil" viewBox="0 0 24 24">
            <path d="M4 20h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4" />
            <path d="M13.5 6.5l4 4" />
        </symbol>
        <symbol id="admin-icon-trash" viewBox="0 0 24 24">
            <path d="M4 7l16 0" />
            <path d="M10 11l0 6" />
            <path d="M14 11l0 6" />
            <path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" />
            <path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" />
        </symbol>
        <symbol id="admin-icon-external-link" viewBox="0 0 24 24">
            <path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" />
            <path d="M11 13l9 -9" />
            <path d="M15 4h5v5" />
        </symbol>
        <symbol id="admin-icon-x" viewBox="0 0 24 24">
            <path d="M18 6l-12 12" />
            <path d="M6 6l12 12" />
        </symbol>
        <symbol id="admin-icon-logout" viewBox="0 0 24 24">
            <path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" />
            <path d="M9 12h12l-3 -3" />
            <path d="M18 15l3 -3" />
        </symbol>
        <symbol id="admin-icon-refresh" viewBox="0 0 24 24">
            <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
            <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
        </symbol>
        <symbol id="admin-icon-device-floppy" viewBox="0 0 24 24">
            <path d="M6 4h10l4 4v10a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2" />
            <path d="M10 14a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
            <path d="M14 4l0 4l-6 0l0 -4" />
        </symbol>
        <symbol id="admin-icon-eye" viewBox="0 0 24 24">
            <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
            <path d="M21 12c-2.4 4 -5.4 6 -9 6s-6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6s6.6 2 9 6" />
        </symbol>
        <symbol id="admin-icon-eye-off" viewBox="0 0 24 24">
            <path d="M3 3l18 18" />
            <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" />
            <path d="M9.3 5.3a10.3 10.3 0 0 1 2.7 -.3c4 0 7 2.3 9 7a16.8 16.8 0 0 1 -2.6 3.7m-3 2a9.8 9.8 0 0 1 -3.4 .6c-4 0 -7 -2.3 -9 -6a17 17 0 0 1 4.2 -5.1" />
        </symbol>
        <symbol id="admin-icon-cloud-upload" viewBox="0 0 24 24">
            <path d="M7 18a4.6 4.4 0 0 1 0 -9a5 4.5 0 0 1 11 2h1a3.5 3.5 0 0 1 0 7h-1" />
            <path d="M9 15l3 -3l3 3" />
            <path d="M12 12l0 9" />
        </symbol>
        <symbol id="admin-icon-photo" viewBox="0 0 24 24">
            <path d="M15 8h.01" />
            <path d="M3 6a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-12" />
            <path d="M3 16l5 -5c.928 -.893 2.072 -.893 3 0l5 5" />
            <path d="M14 14l1 -1c.928 -.893 2.072 -.893 3 0l3 3" />
        </symbol>
        <symbol id="admin-icon-crown" viewBox="0 0 24 24">
            <path d="M12 6l4 6l5 -4l-2 10h-14l-2 -10l5 4l4 -6" />
        </symbol>
        <symbol id="admin-icon-file-description" viewBox="0 0 24 24">
            <path d="M14 3v4a1 1 0 0 0 1 1h4" />
            <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2" />
            <path d="M9 17h6" />
            <path d="M9 13h6" />
        </symbol>
        <symbol id="admin-icon-database" viewBox="0 0 24 24">
            <path d="M4 6a8 3 0 1 0 16 0a8 3 0 1 0 -16 0" />
            <path d="M4 6v6a8 3 0 0 0 16 0v-6" />
            <path d="M4 12v6a8 3 0 0 0 16 0v-6" />
        </symbol>
        <symbol id="admin-icon-grip-vertical" viewBox="0 0 24 24">
            <path d="M8 5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
            <path d="M8 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
            <path d="M8 19a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
            <path d="M14 5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
            <path d="M14 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
            <path d="M14 19a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
        </symbol>
        <symbol id="admin-icon-arrow-left" viewBox="0 0 24 24">
            <path d="M5 12l14 0" />
            <path d="M5 12l6 6" />
            <path d="M5 12l6 -6" />
        </symbol>
        <symbol id="admin-icon-arrow-right" viewBox="0 0 24 24">
            <path d="M5 12l14 0" />
            <path d="M13 18l6 -6" />
            <path d="M13 6l6 6" />
        </symbol>
        <symbol id="admin-icon-clock" viewBox="0 0 24 24">
            <path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" />
            <path d="M12 7v5l3 3" />
        </symbol>
        <symbol id="admin-icon-arrows-exchange" viewBox="0 0 24 24">
            <path d="M4 7h16m-4-4 4 4-4 4M20 17H4m4-4-4 4 4 4" />
        </symbol>
    </defs></svg>
    <?php
}
