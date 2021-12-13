<?php
class PayloadCreator {
  function MakeAuxiliaryTemplateWithProp($input_tree, $gadget_info,
                                         $cand_props_hash_table) {
    if ($gadget_info->class == "" and $gadget_info->real_class == "") {
      return $input_tree; // FuncCall Gadget
    }

    $gen_aux_probability = rand(1, 100);
    if ($gen_aux_probability > MAKE_AUX_PROP) { // Make Aux?
      return $input_tree;
    }

    $generated_gadget_tree = $input_tree;

    $prop_list = $gadget_info->prop_list;

    // Aux prop in prop_list (selecting)
    $aux_props = array();
    foreach ($prop_list as $prop) {
      $gen_aux_sub_probability = rand(1, 100);
      if ($gen_aux_sub_probability <= MAKE_AUX_SUB_PROP) {
        array_push($aux_props, $prop);
      }
    }

    // Aux prop in prop_candidates (Selecting)
    $aux_props_cands = array();
    foreach ($cand_props_hash_table as $cand_class => $props_in_class) {
      foreach ($props_in_class as $prop_in_class) {
        $gen_aux_sub_probability = rand(1, 100);
        if ($gen_aux_sub_probability <= MAKE_AUX_SUB_PROP) {
          if (!array_key_exists($cand_class, $aux_props_cands)) {
            $aux_props_cands[$cand_class] = array();
          }

          $aux_props_cands[$cand_class][$prop_in_class['name']] = $prop_in_class;
          // array_push($aux_props_cands[$cand_class], $prop_in_class);
        }
      }
    }

    // Make auxiliarty properties tree. (prop_list)
    $new_aux_props = array();
    foreach ($aux_props as $aux_prop) {
      $new_aux_prop['name'] = $aux_prop->name;
      $new_aux_prop['value'] = NULL;
      $new_aux_prop['file'] = $gadget_info->file;
      $new_aux_prop['visibility'] = $aux_prop->visibility;
      $new_aux_prop['type'] = "Unknown";

      $new_aux_prop['info'] = new stdClass;
      $new_aux_prop['info']->data = $aux_prop;
      $new_aux_prop['info']->candidates = array(new stdClass);
      $new_aux_prop['info']->candidates[0]->type = "Unknown";
      $new_aux_prop['info']->candidates[0]->value = new stdClass;
      $new_aux_prop['info']->candidates[0]->value->file = $gadget_info->file;
      $new_aux_prop['info']->candidates[0]->value->class = $gadget_info->class;
      $new_aux_prop['info']->candidates[0]->visibility = $aux_prop->visibility;
      $new_aux_prop['info']->candidates[0]->deterministic = false;

      $new_aux_prop['deps'] = array();

      $new_aux_props[$new_aux_prop['name']] = $new_aux_prop;
      // Is it Resonable?? [TODO] - Maybe need to regression test?
    }

    // Find object index where to add auxiliary properties.
    $node_queue = array();
    $node_queue[] = &$generated_gadget_tree['$this'];
    while ($node = &$node_queue[0]) {
      array_shift($node_queue);
      if ($node['type'] != "Object") {
        continue;
      }
      $adding_props = array();

      // Adding Aux prop (prop_list)
      if ($node['value'] == $gadget_info->class and $node['file'] == $gadget_info->file) {
        foreach ($new_aux_props as $new_obj_property) {
          $new_add_flag = True;
          foreach ($node['deps'] as $obj_property_key => $obj_property_value) {
            if ($new_obj_property['name'] == $obj_property_value['name']) {
              $new_add_flag = False;
              break;
            }
          }
          if ($new_add_flag) {
            $adding_props[$new_obj_property['name']] = $new_obj_property;
          }
        }
        $node['deps'] = $node['deps'] + $adding_props;
      }

      // Adding Aux prop (prop_candidates)
      if (array_key_exists($node['value'], $aux_props_cands)) {
        $node['deps'] = $node['deps'] + $aux_props_cands[$node['value']];
      }

      foreach ($node['deps'] as &$child_node) {
        $node_queue[] = &$child_node;
      }
    }

    // Reference clear.
    unset($node_queue);
    unset($child_node);

    return $generated_gadget_tree;
  }

  function MakeGadgetTemplate($gadget_info) {
    if ($gadget_info->class == "" and $gadget_info->real_class == "") {
      return NULL; // FuncCall Gadget
    }

    $generated_gadget_tree = array();
    $generated_gadget_tree['$this']['name'] = '$this';
    $generated_gadget_tree['$this']['type'] = "Object";
    $generated_gadget_tree['$this']['value'] = $gadget_info->class;
    $generated_gadget_tree['$this']['file'] = $gadget_info->file;
    $generated_gadget_tree['$this']['visibility'] = NULL;

    $generated_info = new stdClass;
    $generated_info->data = new stdClass;
    $generated_info->data->name = '$this';
    $generated_info->data->visibility = NULL;
    $generated_info->data->class = $gadget_info->class;
    $generated_info->data->real_class = $gadget_info->real_class;
    $generated_info->data->file = $gadget_info->file;
    $generated_info->data->real_file = $gadget_info->real_file;
    $generated_info->data->class_alias = $gadget_info->class_alias;
    $generated_info->data->parents = array();
    $generated_info->data->parents[0] = new stdClass;
    $generated_info->data->parents[0]->TYPE = "CLASS";
    $generated_info->data->parents[0]->NAME = $gadget_info->class;
    $generated_info->data->parents[0]->FILE = $gadget_info->file;

    $generated_info->candidates = array(new stdClass);
    $generated_info->candidates[0]->type = "Object";
    $generated_info->candidates[0]->method = NULL; // Maybe $this has no method.
    $generated_info->candidates[0]->value = new stdClass;
    $generated_info->candidates[0]->value->file = $gadget_info->file;
    $generated_info->candidates[0]->value->class = $gadget_info->class;
    $generated_info->candidates[0]->visibility = NULL;
    $generated_info->candidates[0]->deterministic = false;

    $generated_gadget_tree['$this']['info'] = $generated_info;

    $generated_gadget_tree['$this']['deps'] = array();

    foreach ($gadget_info->var_list as $argv_name=>$argv_info) {
      $argv_token = explode("->", $argv_name);
      $current_prop = &$generated_gadget_tree;

      for ($i = 0; $i < count($argv_token); $i++) {
        if (!isset($current_prop[$argv_token[$i]]['deps'])) {
          // first visit to leaf node
          if (empty($current_prop[$argv_token[$i]]['name'])) {
            $current_prop[$argv_token[$i]]['name'] = $argv_token[$i];
          }
          if (empty($current_prop[$argv_token[$i]]['value'])) {
            $current_prop[$argv_token[$i]]['value'] = NULL;
          }
          if (empty($current_prop[$argv_token[$i]]['file'])) {
            $current_prop[$argv_token[$i]]['file'] = NULL;
          }
          if (empty($current_prop[$argv_token[$i]]['visibility'])) {
            $current_prop[$argv_token[$i]]['visibility'] = NULL;
          }
          if(empty($current_prop[$argv_token[$i]]['type'])) {
            $current_prop[$argv_token[$i]]['type'] = "Unknown"; // Temp
          }
          $current_prop[$argv_token[$i]]['deps'] = array();
        }
        else {
          // Not first visit
          // P.S) We do not need consider visibility of class method.
          // Becase, next class method was executed by own class method.
          $current_prop[$argv_token[$i]]['type'] = "Object"; // Temp
        }
        if ($i == count($argv_token) - 1) {
          $current_prop[$argv_token[$i]]['info'] = $argv_info;

          if (!empty($argv_info)) {
            $current_prop[$argv_token[$i]]['type'] = $argv_info->candidates[0]->type;
          }
        }
        $current_prop = &$current_prop[$argv_token[$i]]['deps'];
      }
    }

    // Reference clear
    unset($current_prop);

    return $generated_gadget_tree;
  }

  // TODO: Completely random now... T.T
  function SetMutateProperties($input_tree, $file_chain, $chain_info,
                               $cand_class, $cand_foreach, $hinting_infos,
                               $array_hinting, $array_object, $is_arrObj = False) {
    $copied_tree = $input_tree;

    if (empty($copied_tree['$this']) or empty($copied_tree['$this']['deps'])) {
      // If var_list was empty, doing nothing.
      return $copied_tree;
    }

    $queue = array();

    if (!$is_arrObj) {
      $child_node = array(
        'node' => &$copied_tree['$this'],
        'parent_class' => $copied_tree['$this']['value'],
        'parent_file' => $copied_tree['$this']['file'],
        'is_internal_array_obj' => False
      );
      array_push($queue, $child_node);
    }
    else {
      foreach ($copied_tree['$this']['deps'] as &$child) {
        $child_node = array(
          'node' => &$child,
          'parent_class' => $copied_tree['$this']['value'],
          'parent_file' => $copied_tree['$this']['file'],
          'is_internal_array_obj' => False
        );
        array_push($queue, $child_node);
      }
    }

    $gadget_method_list = array();
    foreach ($chain_info as $chain) {
      $gadget_method_list[] = $chain->method;
    }

    $object_prop = array();
    foreach ($chain_info as $chain) {
      if (isset($chain->foreach)) {
        $prop_full_name = $chain->foreach->expr;
        if (substr($prop_full_name, 0, 7) == '$this->') {
          $prop_name = substr($prop_full_name, 7);
          if (!array_key_exists($prop_name, $object_prop)) {
            $object_prop[$prop_name] = array();
          }
          foreach ($chain->var_list as $prop => $prop_info) {
            if ($prop == $prop_full_name) {
              foreach ($prop_info->candidates as $cand) {
                if (!in_array($cand, $object_prop[$prop_name])) {
                  $object_prop[$prop_name][] = $cand;
                }
              }
            }
          }
        }
      }
      if (isset($chain->implicit)) {
        foreach ($chain->implicit->ARGS as $arg) {
          if (substr($arg, 0, 7) == '$this->') {
            $prop_name = substr($arg, 7);
            if (!array_key_exists($prop_name, $object_prop)) {
              $object_prop[$prop_name] = array();
            }
            foreach ($chain->var_list as $prop => $prop_info) {
              if ($prop == $arg) {
                foreach ($prop_info->candidates as $cand) {
                  if (!in_array($cand, $object_prop[$prop_name])) {
                    $object_prop[$prop_name][] = $cand;
                  }
                }
              }
            }
          }
        }
      }
    }

    while (count($queue) != 0) {
      $prop_info = array_shift($queue);
      $prop = &$prop_info['node'];
      $parent_class = $prop_info['parent_class'];
      $parent_file = $prop_info['parent_file'];
      $is_internal_array_obj = $prop_info['is_internal_array_obj'];
      $candidates = $prop['info'];
      $object_prop_flag = false;

      if (array_key_exists($prop['name'], $object_prop)) {
        $prop['type'] = "Object";
        $object_prop_flag = true;
      }

      $mutate_probability = rand(1, 100);
      if ($prop['type'] == "Object") {
        if ($prop['name'] != '$this' and $mutate_probability <= MUTATE_PROP) {
          if ($object_prop_flag) {
            $obj_candidates = $object_prop[$prop['name']];
            if (!empty($obj_candidates)) {
              $choice = rand(0, count($obj_candidates) - 1);

              $parent_class = $obj_candidates[$choice]->value->class;
              $parent_file = $obj_candidates[$choice]->value->file;
              $prop['value'] = $parent_class;
              $prop['visibility'] = $obj_candidates[$choice]->visibility;
              $prop['file'] = $parent_file;
            }
          }
          elseif (rand(0, 100) <= 70) {
            $obj_candidates = $candidates->candidates;
            if (!empty($obj_candidates)) {
              $choice = rand(0, count($obj_candidates) - 1);
              if (empty($obj_candidates[$choice]->value)) {
                var_dump($prop['name']);
                echo $choice . "\n";
                var_dump($obj_candidates);
                exit();
              }

              $parent_class = $obj_candidates[$choice]->value->class;
              $parent_file = $obj_candidates[$choice]->value->file;
              $prop['value'] = $parent_class;
              $prop['visibility'] = $obj_candidates[$choice]->visibility;
              $prop['file'] = $parent_file;
            }
            else {
              $parent_class = $prop['value'];
              $parent_file = $prop['file'];
            }
          }
          else {
            $obj_candidates = $cand_class;
            if (!empty($obj_candidates)) {
              $choice = rand(0, count($obj_candidates) - 1);
              if (empty($obj_candidates[$choice])) {
                var_dump($prop['name']);
                echo $choice . "\n";
                var_dump($obj_candidates);
                exit();
              }

              $parent_class = $obj_candidates[$choice]->class;
              $parent_file = $obj_candidates[$choice]->file;
              $prop['value'] = $parent_class;
              $prop['visibility'] = $obj_candidates[$choice]->visibility;
              $prop['file'] = $parent_file;
            }
            else {
              $parent_class = $prop['value'];
              $parent_file = $prop['file'];
            }
          }
        }
        foreach ($prop['deps'] as &$child) {
          $child_node = array(
            'node' => &$child,
            'parent_class' => $parent_class,
            'parent_file' => $parent_file,
            'is_internal_array_obj' => False
          );
          array_push($queue, $child_node);
        }
      }
      elseif($prop['type'] == "ArrayObject") {
        foreach ($candidates->candidates as $candidate) {
          if ($candidate->value->class == $parent_class and
              $candidate->value->file == $parent_file) {
            $prop['visibility'] = $candidate->visibility;
          }
        }

        foreach ($prop['value'] as $arr_index => $arr_body) {
          // ArrayKey Mutation (All of ArrObj)
          if (count($array_hinting) != 0) {
            $arr_key_rand_val = 2; // Hinting exists
          }
          else {
            $arr_key_rand_val = 1; // Hinting doesnt' exists.
          }
          $key_type = rand(0, $arr_key_rand_val);
          switch ($key_type) {
            case 0:
              $prop['value'][$arr_index]['arr_key'] = $this->GetRandomString();
              break;
            case 1:
              $prop['value'][$arr_index]['arr_key'] = $this->GetRandomInt();
              break;
            case 2:
              $hinting_key_idx = rand(0, count($array_hinting) - 1);
              $prop['value'][$arr_index]['arr_key'] =
                                        $array_hinting[$hinting_key_idx]['dim'];
              break;
          }

          if (is_array($arr_body['arr_value']) and
              array_key_exists("type", $arr_body['arr_value'])) {
            // Obj in ArrObj
            if ($arr_body['arr_value']['type'] == "Object") {
              $prop['value'][$arr_index]['arr_value'] = $this->SetMutateProperties(
                                                          array('$this' =>
                                                                $arr_body['arr_value']),
                                                          $file_chain, $chain_info,
                                                          $cand_class, $cand_foreach,
                                                          $hinting_infos,
                                                          $array_hinting, $array_object
                                                        )['$this'];
            }
          }
          else {
            // Property in ArrObj
            if (is_array($arr_body['arr_value']) and
                is_array(
                  $arr_body['arr_value'][array_keys($arr_body['arr_value'])[0]]
                ) and
                array_key_exists(
                  'arr_key',
                  $arr_body['arr_value'][array_keys($arr_body['arr_value'])[0]]
                ) and
                array_key_exists(
                  'arr_value',
                  $arr_body['arr_value'][array_keys($arr_body['arr_value'])[0]]
                )) {
              $parent_arrobj = new ArrayObject($prop);
              $arrobj_in_arrobj = $parent_arrobj->getArrayCopy();
              $arrobj_in_arrobj['value'] = $arr_body['arr_value'];
              $prop['value'][$arr_index]['arr_value'] =
                        $this->SetMutateProperties(
                          array('$this' => $arrobj_in_arrobj),
                          $file_chain, $chain_info, $cand_class, $cand_foreach,
                          $hinting_infos,
                          $array_hinting, $array_object, True
                        )['$this']['value'];
            }
            else {
              if (count($array_object) == 0 or $is_internal_array_obj == True) {
                $value_type = rand(0, 6);
              }
              elseif (array_key_exists($prop['name'], $cand_foreach) and
                      count($cand_foreach[$prop['name']]) > 0) {
                if (rand(1, 100) <= ARR_OBJ_PROP) {
                  $value_type = rand(7, 7);
                }
                else {
                  $value_type = rand(0, 7);
                }
              }
              else {
                $value_type = rand(0, 6);
              }
              // ArrayValue Mutation (Property in ArrObj)
              switch ($value_type) {
                // String
                case 0:
                  $prop['value'][$arr_index]['arr_value'] = $this->GetRandomString();
                  break;
                // Int
                case 1:
                  $prop['value'][$arr_index]['arr_value'] = $this->GetRandomInt();
                  break;
                // Boolean
                case 2:
                  $prop['value'][$arr_index]['arr_value'] = $this->GetRandomBoolean();
                  break;
                case 3:
                  if (count($array_hinting) != 0) {
                    $prop['value'][$arr_index]['arr_value'] = $this->GetHintingArray(
                                                                $file_chain,
                                                                $array_hinting
                                                              );
                  }
                  else {
                    $prop['value'][$arr_index]['arr_value'] = $this->GetRandomArray(
                                                                $file_chain
                                                              );
                  }
                  break;
                case 4:
                  $prop['value'][$arr_index]['arr_value'] = "";
                  break;
                case 5:
                  $prop['value'][$arr_index]['arr_value'] = $this->getFilePath(
                                                              $file_chain
                                                            );
                  break;
                case 6:
                  $prop['value'][$arr_index]['arr_value'] = $this->getFileDir(
                                                              $file_chain
                                                            );
                  break;
                case 7:
                  $prop['value'][$arr_index]['arr_value'] = array();

                  $array_object_candidates = array($array_object);
                  foreach ($cand_foreach[$prop['name']] as $method_name => $cand_list) {
                    if (in_array($method_name, $gadget_method_list)) {
                        $array_object_candidates = array_merge($array_object_candidates,
                                                               $cand_list);
                    }
                    else {
                      if (rand(1, 100) > ARR_OBJ_PROP) {
                        $array_object_candidates = array_merge($array_object_candidates,
                                                               $cand_list);
                      }
                    }
                  }

                  $arr_obj_key = rand(0, count($array_object_candidates) - 1);
                  $selected_arr_obj = $array_object_candidates[$arr_obj_key]['$this'];
                  // $first_key = array_keys($array_object)[0]; // Always first key?

                  if (count($array_hinting) != 0) {
                    $aux_array = $this->GetHintingArray($file_chain, $array_hinting);
                  }
                  else {
                    $aux_array = $this->GetRandomArray($file_chain);
                  }

                  $isFirstIndex = rand(1, 100);
                  if ($isFirstIndex <= FIRST_INDEX_OBJ_PROP){
                    $obj_index = rand(0, count($aux_array) - 1);
                  }
                  else {
                    $obj_index = 0;
                  }
                  for ($i = 0; $i < count($aux_array); $i++) {
                    $key_name = array_keys($aux_array)[$i];
                    $prop['value'][$arr_index]['arr_value'][$i]['arr_key'] = $key_name;
                    if ($i == $obj_index) {
                      $prop['value'][$arr_index]['arr_value'][$i]['arr_value'] =
                                                                      $selected_arr_obj;
		                  foreach (
                        $prop['value'][$arr_index]['arr_value'][$i]['arr_value']['deps']
                        as &$child) {
                        $child_node = array(
                          'node' => &$child,
                          'parent_class' => $parent_class,
                          'parent_file' => $parent_file,
                          'is_internal_array_obj' => True
                        );
                        array_push($queue, $child_node);
                      }
                      continue;
                    }
                    else {
                      $prop['value'][$arr_index]['arr_value'][$i]['arr_value'] =
                                                                    $aux_array[$key_name];
                    }
                  }
                  break;
              }
            }
          }
        }
      }
      else { // if($prop['type'] == "Unknown"){
        foreach ($candidates->candidates as $candidate) {
          if ($candidate->value->class == $parent_class and
              $candidate->value->file == $parent_file) {
            $prop['visibility'] = $candidate->visibility;
          }
        }

        if (array_key_exists('preserve', $prop)) {
          if ($prop['preserve'] == "ONLY_TYPE") {
            $value_types = array(
              "String" => 0,
              "Int" => 1,
              "Boolean" => 2,
              "Array" => 3,
              "Empty" => 4,
              "FilePath" => 5,
              "DirPath" => 6,
            );
            $value_type = $value_types[$prop['type']];
          }
          elseif ($prop['preserve'] == "TYPE_VALUE") {
            continue;
          }
        }
        else {
          if (count($array_object) == 0 or $is_internal_array_obj == True) {
            $value_type = rand(0, 6);
          }
          elseif (array_key_exists($prop['name'], $cand_foreach) and
                  count($cand_foreach[$prop['name']]) > 0) {
            if (rand(1, 100) <= ARR_OBJ_PROP) {
              $value_type = rand(7, 7);
            }
            else {
              $value_type = rand(0, 7);
            }
          }
          else {
            $value_type = rand(0, 6);
          }
        }

        // Do not change
        if ($mutate_probability > MUTATE_PROP and $prop['type'] != "Unknown") {
          continue;
        }

        switch($value_type) {
          // String
          case 0:
            $prop['type'] = "String";
            $prop['value'] = $this->GetRandomString();
            break;
          // Int
          case 1:
            $prop['type'] = "Int";
            $prop['value'] = $this->GetRandomInt();
            break;
          // Boolean
          case 2:
            $prop['type'] = "Boolean";
            $prop['value'] = $this->GetRandomBoolean();
            break;
          case 3:
            $prop['type'] = "Array";
            if (count($array_hinting) != 0) {
              $prop['value'] = $this->GetHintingArray($file_chain, $array_hinting);
            }
            else {
              $prop['value'] = $this->GetRandomArray($file_chain);
            }
            break;
          case 4:
            $prop['type'] = "Empty";
            $prop['value'] = "";
            break;
          case 5:
            $prop['type'] = "FilePath";
            $prop['value'] = $this->getFilePath($file_chain);
            break;
          case 6:
            $prop['type'] = "DirPath";
            $prop['value'] = $this->getFileDir($file_chain);
            break;
          case 7:
            $prop['type'] = "ArrayObject";
            $prop['value'] = array();

            $array_object_candidates = array($array_object);
            foreach ($cand_foreach[$prop['name']] as $method_name => $cand_list) {
              if (in_array($method_name, $gadget_method_list)) {
                if (rand(1, 100) <= ARR_OBJ_PROP) {
                  $array_object_candidates = array_merge($array_object_candidates,
                                                         $cand_list);
                }
              }
              else {
                if (rand(1, 100) > ARR_OBJ_PROP) {
                  $array_object_candidates = array_merge($array_object_candidates,
                                                         $cand_list);
                }
              }
            }

            $arr_obj_key = rand(0, count($array_object_candidates) - 1);
            $selected_arr_obj = $array_object_candidates[$arr_obj_key]['$this'];
            // $first_key = array_keys($array_object)[0]; // Always first key?

            if (count($array_hinting) != 0) {
              $aux_array = $this->GetHintingArray($file_chain, $array_hinting);
            }
            else {
              $aux_array = $this->GetRandomArray($file_chain);
            }

            $isFirstIndex = rand(1, 100);
            if ($isFirstIndex <= FIRST_INDEX_OBJ_PROP) {
              $obj_index = rand(0, count($aux_array) - 1);
            }
            else {
              $obj_index = 0;
            }

            for ($i = 0; $i < count($aux_array); $i++) {
              $key_name = array_keys($aux_array)[$i];
              $prop['value'][$i]['arr_key'] = $key_name;

              if ($i == $obj_index) {
                $prop['value'][$i]['arr_value'] = $selected_arr_obj;
                foreach ($prop['value'][$i]['arr_value']['deps'] as &$child) {
                  $child_node = array(
                    'node' => &$child,
                    'parent_class' => $parent_class,
                    'parent_file' => $parent_file,
                    'is_internal_array_obj' => True
                  );
                  array_push($queue, $child_node);
                }
                continue;
              }
              else {
                $prop['value'][$i]['arr_value'] = $aux_array[$key_name];
              }
            }
            break;
        }
      }
    }

    // Reference clear
    unset($queue);
    unset($child);
    unset($prop);
    unset($prop_info);
    unset($child_node);

    return $copied_tree;
  }

  private function GetHintingArray($file_chain, $array_hinting) {
    $random_array = array();
    $array_size = rand(1, 5);

    for ($i = 0; $i < $array_size; $i++) {
      $key_type = rand(0, 2);
      switch ($key_type) {
        case 0: // String
          $array_key = $this->GetRandomString();
          break;
        case 1: // Int
          $array_key = $this->GetRandomInt();
          break;
        case 2: // Hinting data
          $hinting_key_idx = rand(0, count($array_hinting) - 1);
          $array_key = $array_hinting[$hinting_key_idx]['dim'];
          break;
      }
      $value_type = rand(0, 6);
      switch ($value_type) {
        case 0:
          $array_value = $this->GetRandomString();
          break;
        case 1:
          $array_value = $this->GetRandomInt();
          break;
        case 2:
          $array_value = $this->GetRandomBoolean();
          break;
        case 3:
          $array_value = $this->GetHintingArray($file_chain, $array_hinting);
          break;
        case 4:
          $array_value = "";
          break;
        case 5:
          $array_value = $this->getFilePath($file_chain);
          break;
        case 6:
          $array_value = $this->getFileDir($file_chain);
          break;
      }
      $random_array[$array_key] = $array_value;
    }
    return $random_array;
  }

  function GetGoalValue($goal_value, $goal_type, $file_chain) {
    if ($goal_type == "Literal") {
      return $goal_value;
    }
    elseif ($goal_type == "Replace") {
      switch ($goal_value) {
        case "MY_FILE":
          return $this->getFilePath($file_chain);
        case "MY_DIR":
          return $this->getFileDir($file_chain);
        case "MY_FILE_NO_EXISTS":
          $no_exists_file = $this->getFilePath($file_chain) . "_no_exists";
          if (file_exists($no_exists_file)) {
            unlink($no_exists_file);
          }
          return $no_exists_file;
        case "MY_DIR_NO_EXISTS":
          $no_exists_dir = substr($this->getFileDir($file_chain), 0, -1) . "_no_exists/";
          if (is_dir($no_exists_dir)) {
            exec(sprintf("rm -rf %s", escapeshellarg($no_exists_dir)));
          }
          return $no_exists_dir;
        case "LFI_FILE":
          return $this->GetLFIFilePath($file_chain);
      }
    }
  }

  function VarExport($input) {
    if (is_array($input)) {
        $buffer = [];
        foreach ($input as $key => $value) {
          $buffer[] = var_export($key, true)."=>".$this->VarExport($value);
        }
        return "[".implode(",",$buffer)."]";
    }
    else {
      return var_export($input, true);
    }
  }

  function VarUnQuote($input_var) {
    // String
    if (substr($input_var, -1) == "'" and substr($input_var, 0, 1) == "'") {
      $input_var_unquoted = substr($input_var,1,-1);
    }
    else {
      $input_var_unquoted = $input_var;
    }
    return $input_var_unquoted;
  }

  function SetSinkProperties($input_tree, $sink_log, $sink_info, $file_chain,
                             $strategy_try = array(), $validated_args = array(),
                             $arr_ObjInfo = array(
                              'is_arrObj' => 0, // 0 => No Array Object,
                                                // 1 => Array Object (Search),
                                                // 2 => Array Object (Set)
                              'target_sink_exec_idx' => NULL,
                              'target_sink_argv_position' => NULL,
                              'target_sink_argv_order' => NULL,
                              'target_sink_argv_idx' => NULL,
                              'strategy' => NULL)
                            ) {
    $oracle_preserve = False;
    if ($validated_args != array()) {
      $oracle_preserve = True;
    }
    $goal_count = array();
    $goal_count['ANY'] = 0;
    $goal_count['REQUIRE_ALL'] = 0;
    $require_list = array();
    $any_flag = False;
    foreach ($sink_info['argvs'] as $argv_idx => $argv_info) {
      if ($argv_idx === "ANY") {
        $goal_argv[$argv_idx] = $argv_info;
        $argv_count = 0;
        array_push($require_list, $goal_argv);
        foreach ($sink_log['sink_branch'] as $logged_branch) {
          $argv_count += (count($logged_branch['argvs']));
        }
        $goal_count['ANY'] = $goal_count['ANY'] + count($argv_info['ARGV_CAND']);
        $any_flag = True;
        break;
      }
      if ($argv_info['Control'] == 'Require') {
        $goal_argv = array();
        $goal_argv[$argv_idx] = $argv_info;
        array_push($require_list, $goal_argv);
        $goal_count['REQUIRE_ALL'] = $goal_count['REQUIRE_ALL'] +
                                    count($argv_info['ARGV_CAND']);
        $goal_count[$argv_idx] = count($argv_info['ARGV_CAND']);
      }
    }

    $strategy_tried = $strategy_try;
    if ($any_flag) {
      $tree_for_sink['require_idx_list'] = array('ANY' => 'ANY');
      $total_try_count = $argv_count * $goal_count['ANY'] * 3;
    }
    else {
      $only_require_count = $goal_count;
      unset($only_require_count['ANY']);
      unset($only_require_count['REQUIRE_ALL']);

      $tree_for_sink['require_idx_list'] = $only_require_count;
      $total_try_count = count($sink_log['sink_branch']) *
                        // count($require_list) *
                        $goal_count['REQUIRE_ALL'] * 3;
    }

    $try_count = 0;
    foreach ($strategy_try as $sink_exec_idx) {
      foreach ($sink_exec_idx as $sink_require_argv) {
        foreach ($sink_require_argv as $sink_argv_idx) {
          $try_count += count($sink_argv_idx);
        }
      }
    }

    $lcs_ret = array();
    $lcs_ret['max_similarity'] = -INF;
    $lcs_ret['max_string'] = NULL;

    if ($try_count >= $total_try_count) {
      // All strategy was executed.
      $tree_for_sink['prop_changed'] = array(
        "changed" => False,
        "usable_default" => False,
        "sink_exec_idx" => NULL,
        "sink_argv_idx" => NULL,
        "sink_goal_idx" => NULL
      );
      $tree_for_sink['strategy'] = $strategy_tried;
      $tree_for_sink['all_tried'] = True;
      $tree_for_sink['input_tree'] = $input_tree;
      $tree_for_sink['lcs_result'] = $lcs_ret;
      return $tree_for_sink;
    }

    // Randomly Select Strategy
    while (True) {
      if ($arr_ObjInfo['is_arrObj'] >= 1) { // Escape when ArrayObject Internal.
        break;
      }

      $target_sink_exec_idx = rand(0, count($sink_log['sink_branch']) - 1);
      if ($any_flag) {
        $target_sink_argv_order = rand(0,
                    count($sink_log['sink_branch'][$target_sink_exec_idx]['argvs']) - 1);
        $target_sink_argv_idx = rand(0, $goal_count['ANY'] - 1);
      }
      else {
        // $target_sink_argv_position = rand(0, count($require_list) - 1);
        $target_sink_argv_position = array_rand($require_list, 1);
        $target_sink_argv_order = array_keys(
                                    $require_list[$target_sink_argv_position]
                                  )[0];
        // $require_index = rand(0, count(array_keys($only_require_count)) - 1);
        $require_index = array_rand($only_require_count, 1);
        $target_sink_argv_idx = rand(0, $only_require_count[$require_index] - 1);
      }

      $strategy_type = rand(0, 2);
      switch ($strategy_type) {
        case 0:
          $strategy = "WHOLE";
          break;
        case 1:
          $strategy = "STRPOS";
          break;
        case 2:
          $strategy = "LCS";
          break;
      }
      if (empty($strategy_try[$target_sink_exec_idx])) {
        $strategy_tried[$target_sink_exec_idx] = array();
      }
      if (empty($strategy_try[$target_sink_exec_idx][$target_sink_argv_order])) {
        $strategy_try[$target_sink_exec_idx][$target_sink_argv_order] = array();
      }
      if (empty($strategy_try[$target_sink_exec_idx][$target_sink_argv_order]
        [$target_sink_argv_idx])) {
        $strategy_tried[$target_sink_exec_idx][$target_sink_argv_order]
        [$target_sink_argv_idx] = array();
      }
      if (!in_array(
            $strategy,
            $strategy_tried[$target_sink_exec_idx][$target_sink_argv_order]
            [$target_sink_argv_idx])) {
        break; // Try new strategy.
      }
    }
    if ($arr_ObjInfo['is_arrObj'] >= 1) {
      $target_sink_exec_idx = $arr_ObjInfo['target_sink_exec_idx'];
      $target_sink_argv_position = $arr_ObjInfo['target_sink_argv_position'];
      $target_sink_argv_order = $arr_ObjInfo['target_sink_argv_order'];
      $target_sink_argv_idx = $arr_ObjInfo['target_sink_argv_idx'];
      $strategy = $arr_ObjInfo['strategy'];
    }
    else {
      array_push(
        $strategy_tried[$target_sink_exec_idx][$target_sink_argv_order][$target_sink_argv_idx],
        $strategy
      );
    }
    if (!array_key_exists(
          $target_sink_argv_order,
          $sink_log['sink_branch'][$target_sink_exec_idx]['argvs']
        )) {
      if (array_key_exists("UsableDefault",
          $require_list[$target_sink_argv_position][$target_sink_argv_order]) and
          $require_list[$target_sink_argv_position][$target_sink_argv_order]
          ['UsableDefault'] == True) {
        $tree_for_sink['prop_changed'] = array(
          "changed" => False,
          "usable_default" => True, // Usable default! Success (Exception)
          "sink_exec_idx" => $target_sink_exec_idx,
          "sink_argv_idx" => $target_sink_argv_order,
          "sink_goal_idx" => $target_sink_argv_idx
        );
        // Force adding.
        $strategy_tried[$target_sink_exec_idx][$target_sink_argv_order]
                      [$target_sink_argv_idx] = array("WHOLE", "STRPOS", "LCS");
        $tree_for_sink['strategy'] = $strategy_tried;
        $tree_for_sink['all_tried'] = False;
        $tree_for_sink['input_tree'] = $input_tree;
        $tree_for_sink['lcs_result'] = $lcs_ret;
        return $tree_for_sink;
      }
      else {
        // Optional parameter does not exists in sink function.
        $tree_for_sink['prop_changed'] = array(
          "changed" => False,
          "usable_default" => False,
          "sink_exec_idx" => NULL,
          "sink_argv_idx" => NULL,
          "sink_goal_idx" => NULL
        );
        $tree_for_sink['strategy'] = $strategy_tried;
        $tree_for_sink['all_tried'] = False;
        $tree_for_sink['input_tree'] = $input_tree;
        $tree_for_sink['lcs_result'] = $lcs_ret;
        return $tree_for_sink;
      }
    }
    else {
      $sink_value = $sink_log['sink_branch'][$target_sink_exec_idx]['argvs']
                    [$target_sink_argv_order];
    }

    $prop_tree = $input_tree;

    if ($any_flag) {
      $goal_value = $this->GetGoalValue(
        $sink_info['argvs']['ANY']['ARGV_CAND']
        [$target_sink_argv_idx]['Goal'],
        $sink_info['argvs']['ANY']['ARGV_CAND']
        [$target_sink_argv_idx]['GoalType'],
        $file_chain
      );
    }
    else {
      $goal_value = $this->GetGoalValue(
        $sink_info['argvs'][$target_sink_argv_order]['ARGV_CAND']
        [$target_sink_argv_idx]['Goal'],
        $sink_info['argvs'][$target_sink_argv_order]['ARGV_CAND']
        [$target_sink_argv_idx]['GoalType'],
        $file_chain
      );
    }

    $sink_var = $this->VarExport($sink_value);
    $goal_var = $this->VarExport($goal_value);

    $prop_selected_one_more = False;

    $node_queue = array();
    // When Oracle set recursion, does not traverse searching phase.
    if ($arr_ObjInfo['is_arrObj'] <= 1) {
      $node_queue[] = &$prop_tree['$this'];
    }
    while ($node = &$node_queue[0]) {
      array_shift($node_queue);
      if ($node['type'] == "Object") {
        foreach ($node['deps'] as &$child_node) {
          $node_queue[] = &$child_node;
        }
      }
      elseif ($node['type'] == "ArrayObject") {
        foreach ($node['value'] as $arr_index => &$arr_body) {
          if (is_array($arr_body['arr_value']) and array_key_exists("type", $arr_body['arr_value'])) {
            foreach ($arr_body['arr_value']['deps'] as &$child_node) {
              $node_queue[] = &$child_node;
            }
          }
          else {
            if (is_array($arr_body['arr_value'])) {
              $first_key = array_keys($arr_body['arr_value'])[0];
              if (is_array($arr_body['arr_value'][$first_key]) and
                  array_key_exists('arr_key', $arr_body['arr_value'][$first_key]) and
                  array_key_exists('arr_value', $arr_body['arr_value'][$first_key])) {
                $parent_arrobj = new ArrayObject($node);
                $arrobj_in_arrobj = $parent_arrobj->getArrayCopy();
                $temp_obj = new ArrayObject($node['value'][$arr_index]['arr_value']);
                $copied_obj = $temp_obj->getArrayCopy();
                $arrobj_in_arrobj['value'] = $copied_obj;
                $arrObj_oracle_info = array(
                  'is_arrObj' => 1,
                  'target_sink_exec_idx' => $target_sink_exec_idx,
                  'target_sink_argv_position' => $target_sink_argv_position,
                  'target_sink_argv_order' => $target_sink_argv_order,
                  'target_sink_argv_idx' => $target_sink_argv_idx,
                  'strategy' => $strategy
                );
                $arrobj_result = $this->SetSinkProperties(
                                          array('$this' => $arrobj_in_arrobj),
                                          $sink_log, $sink_info, $file_chain,
                                          $strategy_try, $validated_args,
                                          $arrObj_oracle_info
                                        );
                $arr_body['arr_value'] = $arrobj_result['input_tree']['$this']['value'];

                if ($strategy == 'LCS') {
                  if ($lcs_ret['max_similarity'] < $arrobj_result['max_similarity']) {
                    $lcs_ret['max_similarity'] = $arrobj_result['max_similarity'];
                    $lcs_ret['max_string'] = $arrobj_result['max_string'];
                  }
                }

                // $prop_selected_one_more == True in ArrayObject?
                if ($arrobj_result['prop_changed']['changed'] == True) {
                  $prop_selected_one_more = True;
                }
              }
            }

            else { // Property in ArrObj
              if ($oracle_preserve == True and
                  strpos($node['value'][$arr_index]['type'], "Oracle-") !== false) {
                // Preserve value
                $node['value'][$arr_index]['oracle_preserve'] = True;
                $prop_selected_one_more = True;
                continue;
              }
              $node['value'][$arr_index]['prop_score'] = 0;
              $prop_value = $node['value'][$arr_index]['arr_value'];
              $prop_var = $this->VarExport($prop_value);

              if ($strategy == "WHOLE") {
                if ($prop_var == $sink_var) {
                  $node['value'][$arr_index]['prop_score'] = 1;
                  $prop_selected_one_more = True;
                }
              }
              elseif ($strategy == "STRPOS") {
                // Removing string quote
                $sink_var_for_strpos_search = $this->VarUnQuote($sink_var);
                $prop_var_for_strpos_search = $this->VarUnQuote($prop_var);

                // Do not strpos in null value
                if ($sink_var_for_strpos_search == "" or
                    $prop_var_for_strpos_search == "" ) {
                  continue;
                }
                // strpos
                if (strpos($prop_var_for_strpos_search,
                           $sink_var_for_strpos_search) !== False) {
                  $node['value'][$arr_index]['prop_score'] = 1;
                  $node['value'][$arr_index]['prop_value'] = $sink_var_for_strpos_search;
                  $prop_selected_one_more = True;
                }
                elseif (strpos($sink_var_for_strpos_search,
                               $prop_var_for_strpos_search) !== False) {
                  $node['value'][$arr_index]['prop_score'] = 1;
                  $node['value'][$arr_index]['prop_value'] = $prop_var_for_strpos_search;
                  $prop_selected_one_more = True;
                }
              }
              elseif ($strategy == "LCS") {
                // Removing string quote
                $sink_var_for_lcs_search = $this->VarUnQuote($sink_var);
                $prop_var_for_lcs_search = $this->VarUnQuote($prop_var);

                $lcs_result = $this->PropertyLCS($sink_var_for_lcs_search,
                                                 $prop_var_for_lcs_search);
                $node['value'][$arr_index]['prop_score'] = $lcs_result['similarity'];
                $node['value'][$arr_index]['prop_value'] = $lcs_result['value'];

                if ($lcs_ret['max_similarity'] <
                    $node['value'][$arr_index]['prop_score']) {
                  $lcs_ret['max_similarity'] = $node['value'][$arr_index]['prop_score'];
                  $lcs_ret['max_string'] = $lcs_result['value'];
                }
                $prop_selected_one_more = True;
              }
            }
          }
        }
      }
      else {
        if ($oracle_preserve == True and strpos($node['type'], "Oracle-") !== false) {
          // Preserve value
          $node['oracle_preserve'] = True;
          $prop_selected_one_more = True;
          continue;
        }
        $node['prop_score'] = 0;
        $prop_value = $node['value'];
        $prop_var = $this->VarExport($prop_value);
        if ($strategy == "WHOLE") {
          if ($prop_var == $sink_var) {
            $node['prop_score'] = 1;
            $prop_selected_one_more = True;
          }
        }
        elseif ($strategy == "STRPOS") {
          // Removing string quote
          $sink_var_for_strpos_search = $this->VarUnQuote($sink_var);
          $prop_var_for_strpos_search = $this->VarUnQuote($prop_var);

          // Do not strpos in null value
          if ($sink_var_for_strpos_search == "" or $prop_var_for_strpos_search == "" ) {
            continue;
          }
          // strpos
          if (strpos($prop_var_for_strpos_search,
                     $sink_var_for_strpos_search) !== False) {
            $node['prop_score'] = 1;
            $node['prop_value'] = $sink_var_for_strpos_search;
            $prop_selected_one_more = True;
          }
          elseif (strpos($sink_var_for_strpos_search,
                         $prop_var_for_strpos_search) !== False) {
            $node['prop_score'] = 1;
            $node['prop_value'] = $prop_var_for_strpos_search;
            $prop_selected_one_more = True;
          }
        }
        elseif ($strategy == "LCS") {
          // Removing string quote
          $sink_var_for_lcs_search = $this->VarUnQuote($sink_var);
          $prop_var_for_lcs_search = $this->VarUnQuote($prop_var);

          $lcs_result = $this->PropertyLCS($sink_var_for_lcs_search,
                                          $prop_var_for_lcs_search);
          $node['prop_score'] = $lcs_result['similarity'];
          $node['prop_value'] = $lcs_result['value'];

          if ($lcs_ret['max_similarity'] < $node['prop_score']) {
            $lcs_ret['max_similarity'] = $node['prop_score'];
            $lcs_ret['max_string'] = $lcs_result['value'];
          }
          $prop_selected_one_more = True;
        }
      }
    }

    // Reference clear
    unset($child_node);

    if ($arr_ObjInfo['is_arrObj'] == 1) {
      // Optional parameter does not exists in sink function.
      $tree_for_sink['prop_changed'] = array(
        "changed" => $prop_selected_one_more,
        "usable_default" => False,
        "sink_exec_idx" => NULL,
        "sink_argv_idx" => NULL,
        "sink_goal_idx" => NULL
      );
      $tree_for_sink['strategy'] = $strategy_tried;
      $tree_for_sink['all_tried'] = False;
      $tree_for_sink['input_tree'] = $input_tree;
      $tree_for_sink['lcs_result'] = $lcs_ret;
      return $tree_for_sink;
    }

    // There is no property to oracle. select another strategy.
    if ($prop_selected_one_more == False) {
      $tree_for_sink['prop_changed'] = array(
        "changed" => False,
        "usable_default" => False,
        "sink_exec_idx" => NULL,
        "sink_argv_idx" => NULL,
        "sink_goal_idx" => NULL,
      );
      $tree_for_sink['strategy'] = $strategy_tried;
      $tree_for_sink['all_tried'] = False;
      $tree_for_sink['input_tree'] = $input_tree;
      $tree_for_sink['lcs_result'] = $lcs_ret;
      return $tree_for_sink;
    }

    // Change prop for sink argv
    $node_queue = array();
    $node_queue[] = &$prop_tree['$this'];
    while ($node = &$node_queue[0]) {
      array_shift($node_queue);
      $is_changed = False;
      if ($node['type'] == "Object") {
        foreach($node['deps'] as &$child_node){
          $node_queue[] = &$child_node;
        }
      }
      elseif ($node['type'] == "ArrayObject") {
        foreach ($node['value'] as $arr_index => &$arr_body) {
          if (is_array($arr_body['arr_value']) and
              array_key_exists("type", $arr_body['arr_value'])) {
            foreach ($arr_body['arr_value']['deps'] as &$child_node) {
              $node_queue[] = &$child_node;
            }
          }
          else {
            if (is_array($arr_body['arr_value'])) {
              $first_key = array_keys($arr_body['arr_value'])[0];
              if (is_array($arr_body['arr_value'][$first_key]) and
                  array_key_exists('arr_key', $arr_body['arr_value'][$first_key]) and
                  array_key_exists('arr_value', $arr_body['arr_value'][$first_key])) {
                $parent_arrobj = new ArrayObject($node);
                $arrobj_in_arrobj = $parent_arrobj->getArrayCopy();
                $temp_obj = new ArrayObject($node['value'][$arr_index]['arr_value']);
                $copied_obj = $temp_obj->getArrayCopy();
                $arrobj_in_arrobj['value'] = $copied_obj;
                $arrObj_oracle_info = array(
                  'is_arrObj' => 2,
                  'target_sink_exec_idx' => $target_sink_exec_idx,
                  'target_sink_argv_position' => $target_sink_argv_position,
                  'target_sink_argv_order' => $target_sink_argv_order,
                  'target_sink_argv_idx' => $target_sink_argv_idx,
                  'strategy' => $strategy
                );
                $arrobj_result = $this->SetSinkProperties(
                                  array('$this' => $arrobj_in_arrobj),
                                  $sink_log, $sink_info, $file_chain,
                                  $strategy_try, $validated_args,
                                  $arrObj_oracle_info
                                );
                $arr_body['arr_value'] = $arrobj_result['input_tree']['$this']['value'];

                // $prop_selected_one_more == True in ArrayObject?
                if ($arrobj_result['prop_changed']['changed'] == True) {
                  $prop_selected_one_more = True;
                }
              }
            }
            else {
              if ($oracle_preserve == True and
                  array_key_exists('oracle_preserve', $node['value'][$arr_index]) and
                  $node['value'][$arr_index]['oracle_preserve'] == True) {
                $node['value'][$arr_index]['type'] = "Oracle-Preserved";
                continue;
              }
              $prop_value = $node['value'][$arr_index]['arr_value'];
              $prop_var = $this->VarExport($prop_value);

              if ($strategy == "WHOLE") {
                if ($node['value'][$arr_index]['prop_score'] == 1) {
                  $chainging_var = str_replace($sink_var, $goal_var, $prop_var);
                  try {
                    eval('$node[\'value\'][$arr_index][\'arr_value\'] = ' .
                         $chainging_var . ';');
                  }
                  catch (ParseError $e) {
                    $node['value'][$arr_index]['arr_value'] =
                                                        $this->VarUnQuote($goal_var);
                  }
                  $is_changed = True;
                }
              }
              elseif ($strategy == "STRPOS") {
                $goal_var_for_strpos = $this->VarUnQuote($goal_var);
                if ($node['value'][$arr_index]['prop_score'] == 1) {
                  $chainging_var = str_replace($node['value'][$arr_index]['prop_value'],
                                               $goal_var_for_strpos, $prop_var);
                  try {
                    eval('$node[\'value\'][$arr_index][\'arr_value\'] = ' .
                         $chainging_var . ';');
                  }
                  catch (ParseError $e) {
                    $node['value'][$arr_index]['arr_value'] =
                                                        $this->VarUnQuote($goal_var);
                  }
                  $is_changed = True;
                }
              }
              elseif ($strategy == "LCS") {
                if ($node['value'][$arr_index]['prop_score'] >=
                    $lcs_ret['max_similarity']) {
                  $goal_var_for_lcs = $this->VarUnQuote($goal_var);
                  // Is included in LCS?
                  if (strpos($prop_var,
                             $node['value'][$arr_index]['prop_value']) !== False) {
                    $chainging_var = str_replace($node['value'][$arr_index]['prop_value'],
                                                 $goal_var_for_lcs,
                                                 $prop_var);
                  }
                  else {
                    $sink_var_for_lcs = $this->VarUnQuote($sink_var);
                    $prop_var_for_lcs = $this->VarUnQuote($prop_var);

                    // Longest matching substring
                    $lms_result = $this->PropertyLMS($sink_var_for_lcs,
                                                     $prop_var_for_lcs);
                    $chainging_var = str_replace($lms_result,
                                                 $goal_var_for_lcs,
                                                 $prop_var);
                  }
                  try {
                    eval('$node[\'value\'][$arr_index][\'arr_value\'] = ' .
                         $chainging_var . ';');
                  }
                  catch (ParseError $e) {
                    $node['value'][$arr_index]['arr_value'] =
                                                        $this->VarUnQuote($goal_var);
                  }
                  $is_changed = True;
                  // PHP Notice will be occured. But, We should ignore them.
                }
              }

              /* Do nothing at arrayObject
              if($is_changed){
                $prop_type = "Oracle-" . gettype($node['value'][$arr_index]['arr_value']);
                $node['value'][$arr_index]['type'] = $prop_type;
              }
              */
              if (!empty($node['value'][$arr_index]['prop_value'])) {
                unset($node['value'][$arr_index]['prop_value']);
              }
              unset($node['value'][$arr_index]['prop_score']);
            }
          }
        }
      }
      else {
        if ($oracle_preserve == True and
            array_key_exists('oracle_preserve', $node) and
            $node['oracle_preserve'] == True) {
          $node['type'] = "Oracle-Preserved";
          continue;
        }
        $prop_value = $node['value'];
        $prop_var = $this->VarExport($prop_value);

        if ($strategy == "WHOLE") {
          if ($node['prop_score'] == 1) {
            $chainging_var = str_replace($sink_var, $goal_var, $prop_var);
            try {
              @eval('$node[\'value\'] = '. $chainging_var . ';');
            }
            catch (ParseError $e) {
              $node['value'] = $this->VarUnQuote($goal_var);
            }
            $is_changed = True;
          }
        }
        elseif ($strategy == "STRPOS") {
          $goal_var_for_strpos = $this->VarUnQuote($goal_var);
          if ($node['prop_score'] == 1) {
            $chainging_var = str_replace($node['prop_value'],
                                         $goal_var_for_strpos, $prop_var);
            try {
              @eval('$node[\'value\'] = '. $chainging_var . ';');
            }
            catch (ParseError $e) {
              $node['value'] = $this->VarUnQuote($goal_var);
            }
            $is_changed = True;
          }
        }
        elseif ($strategy == "LCS") {
          if ($node['prop_score'] >= $lcs_ret['max_similarity']) {
            $goal_var_for_lcs = $this->VarUnQuote($goal_var);
            // Is included in LCS?
            if (strpos($prop_var, $node['prop_value']) !== False) {
              $chainging_var = str_replace($node['prop_value'],
                                           $goal_var_for_lcs,
                                           $prop_var);
            }
            else {
              $sink_var_for_lcs = $this->VarUnQuote($sink_var);
              $prop_var_for_lcs = $this->VarUnQuote($prop_var);

              // Longest matching substring
              $lms_result = $this->PropertyLMS($sink_var_for_lcs,
                                              $prop_var_for_lcs);
              $chainging_var = str_replace($lms_result,
                                           $goal_var_for_lcs,
                                           $prop_var);
            }
            try {
              @eval('$node[\'value\'] = '. $chainging_var . ';');
            }
            catch (ParseError $e) {
              $node['value'] = $this->VarUnQuote($goal_var);
            }
            $is_changed = True;
            // PHP Notice will be occured. But, We should ignore them.
          }
        }

        if ($is_changed) {
          $prop_type = "Oracle-" . gettype($node['value']);
          $node['type'] = $prop_type;
        }

        if (!empty($node['prop_value'])) {
          unset($node['prop_value']);
        }
        unset($node['prop_score']);
      }
    }

    $tree_for_sink['prop_changed'] = array(
        "changed" => True,
        "usable_default" => False,
        "sink_exec_idx" => $target_sink_exec_idx,
        "sink_argv_idx" => $target_sink_argv_order,
        "sink_goal_idx" => $target_sink_argv_idx
    );
    $tree_for_sink['strategy'] = $strategy_tried;
    $tree_for_sink['all_tried'] = False;
    $tree_for_sink['input_tree'] = $prop_tree;
    $tree_for_sink['lcs_result'] = $lcs_ret;

    // Reference clear
    unset($node_queue);
    unset($child);
    unset($child_node);

    return $tree_for_sink;
  }

  private function GetLFIFilePath($file_chain) {
    $helper_file_dir = dirname(realpath($file_chain)) . "/helper";
    $helper_file_name = pathinfo($file_chain)['filename'] . "_lfi_file.php";
    if (is_dir($helper_file_dir) == FALSE) {
      mkdir($helper_file_dir);
    }
    if (file_exists($helper_file_dir . "/" . $helper_file_name) == FALSE) {
      file_put_contents($helper_file_dir . "/" . $helper_file_name, '<?php echo `ls`;');
    }

    $lfi_file_path = $helper_file_dir . "/" . $helper_file_name;

    return $lfi_file_path;
  }

  private function GetFilePath($file_chain) {
    $helper_file_dir = dirname(realpath($file_chain)) . "/helper";
    $helper_file_name = pathinfo($file_chain)['filename'] . "_file.php";
    if (is_dir($helper_file_dir) == FALSE) {
      mkdir($helper_file_dir);
    }
    if (file_exists($helper_file_dir . "/" . $helper_file_name) == FALSE) {
      file_put_contents($helper_file_dir . "/" . $helper_file_name, "TEST");
    }

    $file_path = $helper_file_dir . "/" . $helper_file_name;

    return $file_path;
  }

  private function GetFileDir($file_chain) {
    $helper_file_dir = dirname(realpath($file_chain)) . "/helper";
    $helper_dir_name = pathinfo($file_chain)['filename'] . "_dir/";
    if (is_dir($helper_file_dir) == FALSE) {
      mkdir($helper_file_dir);
    }
    if (is_dir($helper_file_dir . "/" . $helper_dir_name) == FALSE) {
      mkdir($helper_file_dir . "/" . $helper_dir_name);
    }

    $dir_path = $helper_file_dir . "/" . $helper_dir_name;

    return $dir_path;
  }

  function GenerateRandomProperties($template_tree, $gadget_info, $file_chain) {
    $copied_tree = $template_tree;
    $parent_class = $gadget_info->class;
    $parent_file = $gadget_info->file;

    $queue = array();
    if (empty($copied_tree['$this']) or empty($copied_tree['$this']['deps'])) {
      // If var_list was empty, doing nothing.
      return $copied_tree;
    }

    foreach ($copied_tree['$this']['deps'] as &$child) {
      $child_node = array(
        'node' => &$child,
        'parent_class' => $parent_class,
        'parent_file' => $parent_file,
      );
      array_push($queue, $child_node);
    }

    while (count($queue) != 0) {
      $prop_info = array_shift($queue);
      $prop = &$prop_info['node'];
      $parent_class = $prop_info['parent_class'];
      $parent_file = $prop_info['parent_file'];
      $candidates = $prop['info'];

      if ($prop['type'] == "Object") {
        $obj_candidates = $candidates->candidates;
        $choice = rand(0, count($obj_candidates)-1);

        $parent_class = $obj_candidates[$choice]->value->class;
        $parent_file = $obj_candidates[$choice]->value->file;
        $prop['value'] = $parent_class;
        $prop['visibility'] = $obj_candidates[$choice]->visibility;
        $prop['file'] = $obj_candidates[$choice]->value->file;

        foreach ($prop['deps'] as &$child) {
          $child_node = array(
            'node' => &$child,
            'parent_class' => $parent_class,
            'parent_file' => $parent_file,
          );
          array_push($queue, $child_node);
        }
      }
      else { // if($prop['type'] == "Unknown"){
        // var_dump($candidates->candidates[0]);
        // exit();
        // var_dump($candidates->candidates[0]->class);
        foreach ($candidates->candidates as $candidate) {
          if ($candidate->value->class == $parent_class and
              $candidate->value->file == $parent_file) {
            $prop['visibility'] = $candidate->visibility;
          }
        }
        $value_type = rand(0, 6);
        switch($value_type) {
          case 0:
            $prop['type'] = "String";
            $prop['value'] = $this->GetRandomString();
            break;
          case 1:
            $prop['type'] = "Int";
            $prop['value'] = $this->GetRandomInt();
            break;
          case 2:
            $prop['type'] = "Boolean";
            $prop['value'] = $this->GetRandomBoolean();
            break;
          case 3:
            $prop['type'] = "Array";
            $prop['value'] = $this->GetRandomArray($file_chain);
            break;
          case 4:
            $prop['type'] = "Empty";
            $prop['value'] = "";
            break;
          case 5:
            $prop['type'] = "FilePath";
            $prop['value'] = $this->getFilePath($file_chain);
            break;
          case 6:
            $prop['type'] = "DirPath";
            $prop['value'] = $this->getFileDir($file_chain);
        }
      }
    }

    unset($queue);
    unset($child);
    unset($prop);
    unset($prop_info);
    unset($child_node);

    return $copied_tree;
  }

  function PropertyLCS($sink_argv, $propety_value) {
      $ret_value = array();

      if ($sink_argv == $propety_value) {
        $ret_value['similarity'] = 1;
        $ret_value['value'] = $sink_argv;
        return $ret_value;
      }
      $length_sink_argv = strlen($sink_argv);
      $length_property_value = strlen($propety_value);
      if ($length_sink_argv == 0) {
        $ret_value['similarity'] = 0;
        $ret_value['value'] = $sink_argv;
        return $ret_value;
      }

      $L = array_fill(0, $length_sink_argv + 1,
           array_fill(0, $length_property_value + 1, NULL));

      for ($i = 0; $i <= $length_sink_argv; $i++) {
        for ($j = 0; $j <= $length_property_value; $j++) {
          if ($i == 0 || $j == 0) {
            $L[$i][$j] = 0;
          }
          else if ($sink_argv[$i - 1] == $propety_value[$j - 1]) {
            $L[$i][$j] = $L[$i - 1][$j - 1] + 1;
          }
          else {
            $L[$i][$j] = max($L[$i - 1][$j], $L[$i][$j - 1]);
          }
        }
      }

      $index = $L[$length_sink_argv][$length_property_value];
      $length_lcs = $index;

      $lcs = array_fill(0, $index + 1, NULL);
      $lcs[$index] = '';

      $i = $length_sink_argv;
      $j = $length_property_value;
      while ($i > 0 && $j > 0) {
        if ($sink_argv[$i - 1] == $propety_value[$j - 1]) {
          $lcs[$index - 1] = $sink_argv[$i - 1];
          $i--;
          $j--;
          $index--;
        }

        else if ($L[$i - 1][$j] > $L[$i][$j - 1]) {
          $i--;
        }
        else {
          $j--;
        }
      }

      $ret_value['similarity'] = ($length_lcs/$length_sink_argv);
      $ret_value['value'] = implode('', $lcs);
      return $ret_value;
  }

  function PropertyLMS($sink_var, $prop_var) {
    if ($sink_var == "" or $prop_var == "" ) {
      return "";
    }

    $len_sink_var = strlen($sink_var);
    $len_prop_var = strlen($prop_var);

    if ($len_sink_var < $len_prop_var) {
      $shorter = $sink_var;
      $longer = $prop_var;
      $len_shorter = $len_sink_var;
    }
    else {
      $shorter = $prop_var;
      $longer = $sink_var;
      $len_shorter = $len_prop_var;
    }

    //check max len
    $pos = strpos($longer, $shorter);
    if ($pos !== false) {
      return $shorter;
    }

    for ($i = 1, $j = $len_shorter - 1; $j > 0; --$j, ++$i) {
      for ($k = 0; $k <= $i; ++$k) {
        $substr = substr($shorter, $k, $j);
        $pos = strpos($longer, $substr);
        if ($pos !== false) {
          return $substr;
        }
      }
    }

    return "";
  }

  private function GetRandomString() {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $random_string = '';
    $string_length = rand(1, MAX_STR_LENGTH);

    for ($i = 0; $i < $string_length; $i++) {
      $index = rand(0, strlen($characters) - 1);
      $random_string .= $characters[$index];
    }
    return $random_string;
  }

  private function GetRandomInt()  {
    return rand();
  }

  private function GetRandomBoolean() {
    $rand = rand(0, 1);
    if ($rand) {
      return True;
    }
    else {
      return False;
    }
  }

  private function GetRandomArray($file_chain) {
    $random_array = array();
    $array_size = rand(1, 5);

    for ($i = 0; $i < $array_size; $i++) {
      $key_type = rand(0,1);
      switch ($key_type) {
        case 0:
          $array_key = $this->GetRandomString();
          break;
        case 1:
          $array_key = $this->GetRandomInt();
          break;
      }
      $value_type = rand(0, 6);
      switch($value_type) {
        case 0:
          $array_value = $this->GetRandomString();
          break;
        case 1:
          $array_value = $this->GetRandomInt();
          break;
        case 2:
          $array_value = $this->GetRandomBoolean();
          break;
        case 3:
          $array_value = $this->GetRandomArray($file_chain);
          break;
        case 4:
          $array_value = "";
          break;
        case 5:
          $array_value = $this->getFilePath($file_chain);
          break;
        case 6:
          $array_value = $this->getFileDir($file_chain);
          break;
      }
      $random_array[$array_key] = $array_value;
    }

    return $random_array;
  }

  private function GetClassCand($cand_class) {
    $rand = rand(0, count($cand_class) - 1);
    return $cand_class[$rand];
  }
}
