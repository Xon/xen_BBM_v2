<?php
class BBM_BbCode_Parser extends XFCP_BBM_BbCode_Parser
{
    protected static $Bbm_modify_parser = null;
    
	//This function will override the XenForo one. It's not really possible to extend it
	protected function _parseTag()
	{
        $end_character = ']';
		$tagStartPosition = strpos($this->_text, '[', $this->_position);
        $tagStartPosition2 = strpos($this->_text, '{', $this->_position);   
		if ($tagStartPosition === false)
		{
            if ($tagStartPosition2 === false)
            {
                return false;
            }
            $tagStartPosition = $tagStartPosition2;
            $end_character = '}';
		}
        else if ($tagStartPosition2 !== false)
        {
            if ($tagStartPosition2 < $tagStartPosition)
            {
                $tagStartPosition = $tagStartPosition2;
                $end_character = '}';
            }
        }

        if (self::$Bbm_modify_parser === null)
        {
            self::$Bbm_modify_parser = XenForo_Application::get('options')->get('Bbm_modify_parser');
        }
        
        if (self::$Bbm_modify_parser)
        {
            $bbCodesOptionsPattern = '#\\'.$end_character.'(?:/)?[\w\d]+?(?:=(\[([\w\d]+?)(?:=.+?)?\].+?\[/\2\]|[^\[\]])+?)?(?P<closingBracket>\\'.$end_character.')#iu';
            if(	preg_match(
                    $bbCodesOptionsPattern, $this->_text, 
                    $matches, 
                    PREG_OFFSET_CAPTURE,
                    $tagStartPosition
                ) 
                && 
                isset($matches['closingBracket'][1]))
            {
                $tagContentEndPosition = $matches['closingBracket'][1];
            }
            else
            {
                $tagContentEndPosition = false;
            }
        }
        else
        {
            $tagContentEndPosition = strpos($this->_text, $end_character, $tagStartPosition);
        }

		if ($tagContentEndPosition === false)
		{
			return false;
		}

		$tagEndPosition = $tagContentEndPosition + 1;

		if ($tagStartPosition != $this->_position)
		{
			$this->_pushText(substr($this->_text, $this->_position, $tagStartPosition - $this->_position));
			$this->_position = $tagStartPosition;
		}

		if ($this->_text[$tagStartPosition + 1] == '/')
		{
			$success = $this->_parseTagClose($tagStartPosition, $tagEndPosition, $tagContentEndPosition);
		}
		else
		{
			$success = $this->_parseTagOpen($tagStartPosition, $tagEndPosition, $tagContentEndPosition);
		}

		if ($success)
		{
			// successful parse, eat the whole tag
			$this->_position = $tagEndPosition;
		}
		else
		{
			// didn't parse the tag properly, eat the first char ([) and try again
			$this->_pushText($this->_text[$tagStartPosition]);
			$this->_position++;
		}

		return true;
	}
    
    //This function will override the XenForo one. It's not really possible to extend it
	protected function _pushTagOpen($tagName, $tagOption = null, $originalText = '')
	{
		$tagNameLower = strtolower($tagName);

		$invalidTag = false;

		$tagInfo = $this->_getTagRule($tagNameLower);
		if (!$tagInfo)
		{
			// didn't find tag
			$invalidTag = true;
		}
		else if (!empty($this->_parserStates['plainText']))
		{
			$invalidTag = true;
		}     
		else
		{
            $topOfStack = count($this->_tagStack) - 1;
            if (isset($this->_tagStack[$topOfStack]))
            {
                $parent = $this->_tagStack[$topOfStack];
                $allowedChildren = $parent['allowedChildren'];
                if( isset($parent['allowedChildren']) &&
                    $parent['allowedChildren'] !== null && 
                    !isset($parent['allowedChildren'][$tagNameLower]))
                {
                    $invalidTag = true;
                }
            }

            if (isset($tagInfo['allowedParents']))
            {
                $notFound = true;
                for ($i = $topOfStack; $i >= 0; $i--)
                {
                    if (isset($tagInfo['allowedParents'][ $this->_tagStack[$i]['tag'] ]))
                    {
                        $notFound = false;
                        break;
                    }
                }
                if ($notFound)
                {
                    $invalidTag = true;
                }
            } 
            
			$hasOption = (is_string($tagOption) && $tagOption !== '');

			if (isset($tagInfo['hasOption']) && $hasOption !== $tagInfo['hasOption'])
			{
				// we expecting an option and not given one or vice versa
				$invalidTag = true;
			}
			else if ($hasOption && isset($tagInfo['optionRegex']) && !preg_match($tagInfo['optionRegex'], $tagOption))
			{
				$invalidTag = true;
			}
			else if (!empty($tagInfo['parseCallback']))
			{
				$tagInfoChanges = call_user_func($tagInfo['parseCallback'], $tagInfo, $tagOption);
				if ($tagInfoChanges === false)
				{
					$invalidTag = true;
				}
				else if (is_array($tagInfoChanges) && isset($tagInfoChanges['plainChildren']))
				{
					$tagInfo['plainChildren'] = true;
				}
			}
		}

		if ($invalidTag)
		{
			$this->_pushText($originalText);
			return;
		}

        $allowedChildren = null;
        if (isset($tagInfo['allowedChildren']))
        {
            $allowedChildren = $tagInfo['allowedChildren'];
        }
            
		$this->_mergeTrailingText();

		$index = count($this->_context);
		$this->_context[$index] = array(
			'tag' => $tagNameLower,
			'option' => $tagOption,
			'original' => ($originalText ? array($originalText, "[/$tagName]") : null),
			'children' => array()
		);

		array_push($this->_tagStack, array(
            'allowedChildren'  => $allowedChildren,
			'tag' => $tagNameLower,
			'option' => $tagOption,
			'originalText' => $originalText,
			'tagContext' => &$this->_context[$index],
			'parentContext' => &$this->_context
		));
		$this->_context =& $this->_context[$index]['children'];

		if (!empty($tagInfo['plainChildren']))
		{
			$this->_parserStates['plainText'] = $tagNameLower;
		}
	} 
    
	protected function _pushTagClose($tagName, $originalText = '')
	{
		$tagNameLower = strtolower($tagName);
        
        return parent::_pushTagClose($tagName, $originalText);
    }        
}