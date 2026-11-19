<?php

return [
    'empty' => 'The barcode is empty.',
    'check_digit' => 'This barcode is invalid: the last digit (check digit) does not match. Check what you typed.',
    'format' => 'Barcode not recognised. Use an EAN-13, EAN-8, UPC-A, or a code of 4 to 32 characters (letters, digits, - . /).',
    'taken' => 'This barcode is already used by “:product”.',
    'duplicate' => 'Barcode :code is entered twice.',
    'variable_article' => 'A product with variants has no barcode of its own: set one on each variant.',
    'already_set' => 'This product already has a barcode: it is never replaced automatically.',
    'not_found' => 'No product has this barcode.',
    'exhausted' => 'Could not generate a barcode right now. Try again.',
    'generated' => '{0} No barcode to create.|{1} 1 barcode created.|[2,*] :count barcodes created.',
];
