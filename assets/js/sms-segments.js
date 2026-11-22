/*
 * SMS segment estimation.
 *
 * GSM-7:
 *   Single segment: 160 septets
 *   Multipart: 153 septets per segment
 *
 * UCS-2:
 *   Single segment: 70 UTF-16 code units
 *   Multipart: 67 UTF-16 code units per segment
 *
 * Estimates only. Provider billing may differ.
 */
(function (global) {
    'use strict';

    const GSM_BASIC =
        '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ' +
        'ÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?' +
        '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ`' +
        '¿abcdefghijklmnopqrstuvwxyzäöñüà';

    const GSM_EXTENDED = '^{}\\[~]|€\f';

    function calculate(message) {
        const text = String(message || '');
        const characters = Array.from(text).length;

        let gsmUnits = 0;
        let gsmCompatible = true;

        for (const character of text) {
            if (GSM_BASIC.includes(character)) {
                gsmUnits += 1;
            } else if (GSM_EXTENDED.includes(character)) {
                gsmUnits += 2;
            } else {
                gsmCompatible = false;
                break;
            }
        }

        const encoding = gsmCompatible ? 'GSM-7' : 'Unicode';
        const units = gsmCompatible ? gsmUnits : text.length;
        const singleLimit = gsmCompatible ? 160 : 70;
        const multipartLimit = gsmCompatible ? 153 : 67;

        const segments = units === 0
            ? 0
            : units <= singleLimit
                ? 1
                : Math.ceil(units / multipartLimit);

        return {
            characters: characters,
            encoding: encoding,
            units: units,
            segments: segments,
            estimatedCreditsPerRecipient: segments,
            remainingUnits: segments === 0
                ? singleLimit
                : segments === 1
                    ? singleLimit - units
                    : segments * multipartLimit - units
        };
    }

    global.PhilSMSSegments = Object.freeze({
        calculate: calculate
    });
})(window);
