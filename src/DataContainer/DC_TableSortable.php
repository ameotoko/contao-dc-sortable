<?php
/**
 * @author Andrey Vinichenko <andrey.vinichenko@gmail.com>
 */

namespace Ameotoko\DCSortableBundle\DataContainer;

use Contao\ArrayUtil;
use Contao\Config;
use Contao\Database;
use Contao\Date;
use Contao\DC_Table;
use Contao\Encryption;
use Contao\Image;
use Contao\Input;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;

class DC_TableSortable extends DC_Table
{
    /**
     * @todo possibly override:
     *   render drag-handle inline conditionally (no need for button_callback)
     */
    protected function listView(): string
    {
        $packages = System::getContainer()->get('assets.packages');

        // $GLOBALS['TL_CSS'][] = $packages->getUrl('sortable.css', 'ameotokodcsortable');
        $GLOBALS['TL_JAVASCRIPT'][] = $packages->getUrl('backend.js', 'ameotokodcsortable');

        $table = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) == 6 ? $this->ptable : $this->strTable;
        $orderBy = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'] ?? array();
        $firstOrderBy = preg_replace('/\s+.*$/', '', $orderBy[0]);

        if (\is_array($this->orderBy) && !empty($this->orderBy[0]))
        {
            $orderBy = $this->orderBy;
            $firstOrderBy = $this->firstOrderBy;
        }

        // Check the default labels (see #509)
        $labelNew = $GLOBALS['TL_LANG'][$this->strTable]['new'] ?? $GLOBALS['TL_LANG']['DCA']['new'];

        $query = "SELECT * FROM " . $this->strTable;

        if (!empty($this->procedure))
        {
            $query .= " WHERE " . implode(' AND ', $this->procedure);
        }

        if (!empty($this->root) && \is_array($this->root))
        {
            $query .= (!empty($this->procedure) ? " AND " : " WHERE ") . "id IN(" . implode(',', array_map('\intval', $this->root)) . ")";
        }

        if (\is_array($orderBy) && $orderBy[0])
        {
            foreach ($orderBy as $k=>$v)
            {
                list($key, $direction) = explode(' ', $v, 2) + array(null, null);

                // If there is no direction, check the global flag in sorting mode 1 or the field flag in all other sorting modes
                if (!$direction)
                {
                    if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) == 1 && isset($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag']) && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] % 2) == 0)
                    {
                        $direction = 'DESC';
                    }
                    elseif (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['flag']) && ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['flag'] % 2) == 0)
                    {
                        $direction = 'DESC';
                    }
                }

                if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['eval']['findInSet'] ?? null)
                {
                    $direction = null;

                    if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback'] ?? null))
                    {
                        $strClass = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback'][0];
                        $strMethod = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback'][1];

                        $this->import($strClass);
                        $keys = $this->$strClass->$strMethod($this);
                    }
                    elseif (\is_callable($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback'] ?? null))
                    {
                        $keys = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback']($this);
                    }
                    else
                    {
                        $keys = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options'] ?? array();
                    }

                    if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['eval']['isAssociative'] ?? null) || ArrayUtil::isAssoc($keys))
                    {
                        $keys = array_keys($keys);
                    }

                    $orderBy[$k] = $this->Database->findInSet($v, $keys);
                }
                elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['flag'] ?? null, array(5, 6, 7, 8, 9, 10)))
                {
                    $orderBy[$k] = "CAST($key AS SIGNED)"; // see #5503
                }

                if ($direction)
                {
                    $orderBy[$k] = $key . ' ' . $direction;
                }
            }

            if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) == 3)
            {
                $firstOrderBy = 'pid';
                $showFields = $GLOBALS['TL_DCA'][$table]['list']['label']['fields'];

                $query .= " ORDER BY (SELECT " . Database::quoteIdentifier($showFields[0]) . " FROM " . $this->ptable . " WHERE " . $this->ptable . ".id=" . $this->strTable . ".pid), " . implode(', ', $orderBy);

                // Set the foreignKey so that the label is translated
                if (!($GLOBALS['TL_DCA'][$table]['fields']['pid']['foreignKey'] ?? null))
                {
                    $GLOBALS['TL_DCA'][$table]['fields']['pid']['foreignKey'] = $this->ptable . '.' . $showFields[0];
                }

                // Remove the parent field from label fields
                array_shift($showFields);
                $GLOBALS['TL_DCA'][$table]['list']['label']['fields'] = $showFields;
            }
            else
            {
                $query .= " ORDER BY " . implode(', ', $orderBy);
            }
        }

        $objRowStmt = $this->Database->prepare($query);

        if ($this->limit)
        {
            $arrLimit = explode(',', $this->limit) + array(null, null);
            $objRowStmt->limit($arrLimit[1], $arrLimit[0]);
        }

        $objRow = $objRowStmt->execute($this->values);

        // Display buttos
        $return = Message::generate() . '
<div id="tl_buttons">' . ((Input::get('act') == 'select' || $this->ptable) ? '
<a href="' . $this->getReferer(true, $this->ptable) . '" class="header_back" title="' . StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle']) . '" accesskey="b" onclick="Backend.getScrollOffset()">' . $GLOBALS['TL_LANG']['MSC']['backBT'] . '</a> ' : (isset($GLOBALS['TL_DCA'][$this->strTable]['config']['backlink']) ? '
<a href="contao/main.php?' . $GLOBALS['TL_DCA'][$this->strTable]['config']['backlink'] . '" class="header_back" title="' . StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle']) . '" accesskey="b" onclick="Backend.getScrollOffset()">' . $GLOBALS['TL_LANG']['MSC']['backBT'] . '</a> ' : '')) . ((Input::get('act') != 'select' && !($GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['config']['notCreatable'] ?? null)) ? '
<a href="' . ($this->ptable ? $this->addToUrl('act=create' . ((($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) < 4) ? '&amp;mode=2' : '') . '&amp;pid=' . $this->intId) : $this->addToUrl('act=create')) . '" class="header_new" title="' . StringUtil::specialchars($labelNew[1] ?? '') . '" accesskey="n" onclick="Backend.getScrollOffset()">' . $labelNew[0] . '</a> ' : '') . $this->generateGlobalButtons() . '
</div>';

        // Return "no records found" message
        if ($objRow->numRows < 1)
        {
            $return .= '
<p class="tl_empty">' . $GLOBALS['TL_LANG']['MSC']['noResult'] . '</p>';
        }

        // List records
        else
        {
            $result = $objRow->fetchAllAssoc();

            $return .= ((Input::get('act') == 'select') ? '
<form id="tl_select" class="tl_form' . ((Input::get('act') == 'select') ? ' unselectable' : '') . '" method="post" novalidate>
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="tl_select">
<input type="hidden" name="REQUEST_TOKEN" value="' . REQUEST_TOKEN . '">' : '') . '
<div class="tl_listing_container list_view" id="tl_listing"' . $this->getPickerValueAttribute() . '>' . ((Input::get('act') == 'select' || $this->strPickerFieldType == 'checkbox') ? '
<div class="tl_select_trigger">
<label for="tl_select_trigger" class="tl_select_label">' . $GLOBALS['TL_LANG']['MSC']['selectAll'] . '</label> <input type="checkbox" id="tl_select_trigger" onclick="Backend.toggleCheckboxes(this)" class="tl_tree_checkbox">
</div>' : '') . '
<table class="tl_listing' . (($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null) ? ' showColumns' : '') . ($this->strPickerFieldType ? ' picker unselectable' : '') . '">';

            // Automatically add the "order by" field as last column if we do not have group headers
            if ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null)
            {
                $blnFound = false;

                // Extract the real key and compare it to $firstOrderBy
                foreach ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['fields'] as $f)
                {
                    if (strpos($f, ':') !== false)
                    {
                        list($f) = explode(':', $f, 2);
                    }

                    if ($firstOrderBy == $f)
                    {
                        $blnFound = true;
                        break;
                    }
                }

                if (!$blnFound)
                {
                    $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['fields'][] = $firstOrderBy;
                }
            }

            // Generate the table header if the "show columns" option is active
            if ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null)
            {
                $return .= '
  <thead>
    <tr>';

                foreach ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['fields'] as $f)
                {
                    if (strpos($f, ':') !== false)
                    {
                        list($f) = explode(':', $f, 2);
                    }

                    $return .= '
        <th class="tl_folder_tlist col_' . $f . (($f == $firstOrderBy) ? ' ordered_by' : '') . '">' . (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['label'] ?? null) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['label'][0] : ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['label'] ?? null)) . '</th>';
                }

                $return .= '
        <th class="tl_folder_tlist tl_right_nowrap"></th>
    </tr>
</thead>
<tbody>';
            }

            // Process result and add label and buttons
            $remoteCur = false;
            $groupclass = 'tl_folder_tlist';

            foreach ($result as $row)
            {
                $args = array();
                $this->current[] = $row['id'];
                $showFields = $GLOBALS['TL_DCA'][$table]['list']['label']['fields'];

                // Label
                foreach ($showFields as $k=>$v)
                {
                    // Decrypt the value
                    if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['encrypt'] ?? null)
                    {
                        $row[$v] = Encryption::decrypt(StringUtil::deserialize($row[$v]));
                    }

                    if (strpos($v, ':') !== false)
                    {
                        list($strKey, $strTable) = explode(':', $v, 2);
                        list($strTable, $strField) = explode('.', $strTable, 2);

                        $objRef = $this->Database->prepare("SELECT " . Database::quoteIdentifier($strField) . " FROM " . $strTable . " WHERE id=?")
                            ->limit(1)
                            ->execute($row[$strKey]);

                        $args[$k] = $objRef->numRows ? $objRef->$strField : '';
                    }
                    elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['flag'] ?? null, array(5, 6, 7, 8, 9, 10)))
                    {
                        if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['rgxp'] ?? null) == 'date')
                        {
                            $args[$k] = $row[$v] ? Date::parse(Config::get('dateFormat'), $row[$v]) : '-';
                        }
                        elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['rgxp'] ?? null) == 'time')
                        {
                            $args[$k] = $row[$v] ? Date::parse(Config::get('timeFormat'), $row[$v]) : '-';
                        }
                        else
                        {
                            $args[$k] = $row[$v] ? Date::parse(Config::get('datimFormat'), $row[$v]) : '-';
                        }
                    }
                    elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['isBoolean'] ?? null) || (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['inputType'] ?? null) == 'checkbox' && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['multiple'] ?? null)))
                    {
                        $args[$k] = $row[$v] ? $GLOBALS['TL_LANG']['MSC']['yes'] : $GLOBALS['TL_LANG']['MSC']['no'];
                    }
                    elseif (isset($row[$v]))
                    {
                        $row_v = StringUtil::deserialize($row[$v]);

                        if (\is_array($row_v))
                        {
                            $args_k = array();

                            foreach ($row_v as $option)
                            {
                                $args_k[] = $GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$option] ?: $option;
                            }

                            $args[$k] = implode(', ', $args_k);
                        }
                        elseif (isset($GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$row[$v]]))
                        {
                            $args[$k] = \is_array($GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$row[$v]]) ? $GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$row[$v]][0] : $GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$row[$v]];
                        }
                        elseif ((($GLOBALS['TL_DCA'][$table]['fields'][$v]['eval']['isAssociative'] ?? null) || ArrayUtil::isAssoc($GLOBALS['TL_DCA'][$table]['fields'][$v]['options'] ?? null)) && isset($GLOBALS['TL_DCA'][$table]['fields'][$v]['options'][$row[$v]]))
                        {
                            $args[$k] = $GLOBALS['TL_DCA'][$table]['fields'][$v]['options'][$row[$v]] ?? null;
                        }
                        else
                        {
                            $args[$k] = $row[$v];
                        }
                    }
                    else
                    {
                        $args[$k] = null;
                    }
                }

                // Shorten the label it if it is too long
                $label = vsprintf(!empty($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['format']) ? $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['format'] : '%s', $args);

                if (($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['maxCharacters'] ?? null) > 0 && $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['maxCharacters'] < \strlen(strip_tags($label)))
                {
                    $label = trim(StringUtil::substrHtml($label, $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['maxCharacters'])) . ' …';
                }

                // Remove empty brackets (), [], {}, <> and empty tags from the label
                $label = preg_replace('/\( *\) ?|\[ *] ?|{ *} ?|< *> ?/', '', $label);
                $label = preg_replace('/<[^>]+>\s*<\/[^>]+>/', '', $label);

                // Build the sorting groups
                if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) > 0)
                {
                    $current = $row[$firstOrderBy];
                    $orderBy = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'] ?? array();
                    $sortingMode = (\count($orderBy) == 1 && $firstOrderBy == $orderBy[0] && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['flag'] ?? null)) ? $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] : ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['flag'] ?? null);
                    $remoteNew = $this->formatCurrentValue($firstOrderBy, $current, $sortingMode);

                    // Add the group header
                    if (($remoteNew != $remoteCur || $remoteCur === false) && !($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['disableGrouping'] ?? null))
                    {
                        $eoCount = -1;
                        $group = $this->formatGroupHeader($firstOrderBy, $remoteNew, $sortingMode, $row);
                        $remoteCur = $remoteNew;

                        $return .= '
  <tr>
    <td colspan="2" class="' . $groupclass . '">' . $group . '</td>
  </tr>';
                        $groupclass = 'tl_folder_list';
                    }
                }

                $return .= '
  <tr class="click2edit toggle_select hover-row" data-id="' . $row['id'] . '">
    ';

                $colspan = 1;

                // Call the label_callback ($row, $label, $this)
                if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'] ?? null) || \is_callable($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'] ?? null))
                {
                    if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'] ?? null))
                    {
                        $strClass = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'][0];
                        $strMethod = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'][1];

                        $this->import($strClass);
                        $args = $this->$strClass->$strMethod($row, $label, $this, $args);
                    }
                    elseif (\is_callable($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'] ?? null))
                    {
                        $args = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback']($row, $label, $this, $args);
                    }

                    // Handle strings and arrays
                    if (!($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null))
                    {
                        $label = \is_array($args) ? implode(' ', $args) : $args;
                    }
                    elseif (!\is_array($args))
                    {
                        $args = array($args);
                        $colspan = \count($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['fields'] ?? array());
                    }
                }

                // Show columns
                if ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null)
                {
                    foreach ($args as $j=>$arg)
                    {
                        $field = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['fields'][$j] ?? null;

                        if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['foreignKey']))
                        {
                            $value = $arg ?: '-';
                        }
                        else
                        {
                            $value = (string) $arg !== '' ? $arg : '-';
                        }

                        $return .= '<td colspan="' . $colspan . '" class="tl_file_list col_' . explode(':', $field, 2)[0] . ($field == $firstOrderBy ? ' ordered_by' : '') . '">' . $value . '</td>';
                    }
                }
                else
                {
                    $return .= '<td class="tl_file_list">' . $label . '</td>';
                }

                // Buttons ($row, $table, $root, $blnCircularReference, $childs, $previous, $next)
                $return .= ((Input::get('act') == 'select') ? '
    <td class="tl_file_list tl_right_nowrap"><input type="checkbox" name="IDS[]" id="ids_' . $row['id'] . '" class="tl_tree_checkbox" value="' . $row['id'] . '"></td>' : '
    <td class="tl_file_list tl_right_nowrap">' . $this->generateButtons($row, $this->strTable, $this->root) . ($this->strPickerFieldType ? $this->getPickerInputField($row['id']) : '') . '</td>') . '
  </tr>';
            }

            // Close the table
            $return .= '
</tbody></table>' . ($this->strPickerFieldType == 'radio' ? '
<div class="tl_radio_reset">
<label for="tl_radio_reset" class="tl_radio_label">' . $GLOBALS['TL_LANG']['MSC']['resetSelected'] . '</label> <input type="radio" name="picker" id="tl_radio_reset" value="" class="tl_tree_radio">
</div>' : '') . '
</div>';

            // Add another panel at the end of the page
            if (strpos($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['panelLayout'] ?? '', 'limit') !== false)
            {
                $return .= $this->paginationMenu();
            }

            // Close the form
            if (Input::get('act') == 'select')
            {
                // Submit buttons
                $arrButtons = array();

                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null))
                {
                    $arrButtons['edit'] = '<button type="submit" name="edit" id="edit" class="tl_submit" accesskey="s">' . $GLOBALS['TL_LANG']['MSC']['editSelected'] . '</button>';
                }

                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notDeletable'] ?? null))
                {
                    $arrButtons['delete'] = '<button type="submit" name="delete" id="delete" class="tl_submit" accesskey="d" onclick="return confirm(\'' . $GLOBALS['TL_LANG']['MSC']['delAllConfirm'] . '\')">' . $GLOBALS['TL_LANG']['MSC']['deleteSelected'] . '</button>';
                }

                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notCopyable'] ?? null))
                {
                    $arrButtons['copy'] = '<button type="submit" name="copy" id="copy" class="tl_submit" accesskey="c">' . $GLOBALS['TL_LANG']['MSC']['copySelected'] . '</button>';
                }

                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null))
                {
                    $arrButtons['override'] = '<button type="submit" name="override" id="override" class="tl_submit" accesskey="v">' . $GLOBALS['TL_LANG']['MSC']['overrideSelected'] . '</button>';
                }

                // Call the buttons_callback (see #4691)
                if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['select']['buttons_callback'] ?? null))
                {
                    foreach ($GLOBALS['TL_DCA'][$this->strTable]['select']['buttons_callback'] as $callback)
                    {
                        if (\is_array($callback))
                        {
                            $this->import($callback[0]);
                            $arrButtons = $this->{$callback[0]}->{$callback[1]}($arrButtons, $this);
                        }
                        elseif (\is_callable($callback))
                        {
                            $arrButtons = $callback($arrButtons, $this);
                        }
                    }
                }

                if (\count($arrButtons) < 3)
                {
                    $strButtons = implode(' ', $arrButtons);
                }
                else
                {
                    $strButtons = array_shift($arrButtons) . ' ';
                    $strButtons .= '<div class="split-button">';
                    $strButtons .= array_shift($arrButtons) . '<button type="button" id="sbtog">' . Image::getHtml('navcol.svg') . '</button> <ul class="invisible">';

                    foreach ($arrButtons as $strButton)
                    {
                        $strButtons .= '<li>' . $strButton . '</li>';
                    }

                    $strButtons .= '</ul></div>';
                }

                $return .= '
</div>
<div class="tl_formbody_submit" style="text-align:right">
<div class="tl_submit_container">
  ' . $strButtons . '
</div>
</div>
</form>';
            }
        }

        return $return . '<script>Backend.makeListViewSortable("#tl_listing table.tl_listing tbody")</script>';
    }
}
