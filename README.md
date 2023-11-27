# DC_SortableTable

A DataContainer driver for [Contao 5 CMS][1], that extends core `DC_Table` driver
with custom "drag'n'drop" sorting capability.

## Install

```bash
composer require ameotoko/contao-dc-sortable
```

## Use in your data container

This is a minimal DCA configuration to make it work:

```php
// tl_custom.php
use Ameotoko\DCSortableBundle\DataContainer\DC_TableSortable;

$GLOBALS['TL_DCA']['tl_custom'] = [
    'config' => [
        'dataContainer' => DC_TableSortable::class, // enable the driver
    ],
    
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTED, // this mode is required
            'flag' => DataContainer::SORT_ASC,
            'fields' => ['sorting'] // drag'n'drop table must be sorted by a sorting field
        ],

        // add the drag handle
        'operations' => [..., 'drag' => [
            'icon' => 'drag.svg',
            'attributes' => 'class="drag-handle" aria-hidden="true"',
            'button_callback' => static function ($row, $href, $label, $title, $icon, $attributes) {
                return \Contao\Image::getHtml($icon, $label, $attributes);
            }
        ]]
    ],
    
    // add required fields
    'fields' => [
        'id' => [
            'sql' => ['type' => Types::INTEGER, 'unsigned' => true, 'autoincrement' => true],
            'search' => true
        ],
        'sorting' => [
            'sql' => ['type' => Types::INTEGER, 'unsigned' => true, 'default' => 0]
        ],
    ]
```

[1]: https://contao.org
