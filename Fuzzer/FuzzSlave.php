<?php
if (PHP_VERSION >= "7.2") {
  if (PHP_VERSION >= "8.4") {
    require_once __DIR__ . '/../Lib/rabbitmq_php8/vendor/autoload.php';  
  }
  else {
    require_once __DIR__ . '/../Lib/rabbitmq_php7/vendor/autoload.php';
  }
}
else {
  require_once __DIR__ . '/../Lib/rabbitmq_php/vendor/autoload.php';
}
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

if (PHP_VERSION >= "7.2") {
  if (PHP_VERSION >= "8.4") {
    require_once __DIR__ . '/../Lib/PHP-Parser8/vendor/autoload.php';
  }
  else {
    require_once __DIR__ . '/../Lib/PHP-Parser7/vendor/autoload.php';
  }
}
else {
  require_once __DIR__ . '/../Lib/PHP-Parser/vendor/autoload.php';
}
use PhpParser\ParserFactory;

class FuzzSlave {
  function __construct($file_chain, $chain_info,
                       $cand_methods, $cand_props, $cand_foreach,
                       $file_inst, $rabbitmq_settings) {
    $this->file_chain = $file_chain;
    $this->file_inst = $file_inst;

    $this->chain_info = $chain_info;
    $this->chain = $this->chain_info->chain;
    $this->gadget = $this->chain_info->var_list->gadget_info;

    $this->cand_methods = $cand_methods;
    $this->cand_props = $cand_props;
    $this->cand_props_hash_table = $this->MakePropCandidatesHT($cand_props);
    $this->cand_class = $this->GetClassCandidates($cand_methods, $cand_props);
    $this->cand_foreach = $this->GetForeachCandidates($cand_foreach);

    $this->rabbitmq_settings = $rabbitmq_settings;
    $this->rabbitmq_connection = $this->RabbitMQInit($this->rabbitmq_settings);

    $this->seed_selected_log = array();
    $this->probably_exploitble_count = 0;
    $this->oracle_exec_count = 0;

    $this->hinting_data = $this->GetConstraints($this->file_inst,
                                                $this->rabbitmq_settings,
                                                $this->rabbitmq_connection);
    $this->hinting_infos = array();
    // var_dump($this->hinting_data);
    if (PHP_VERSION >= "7.2") {
      if(PHP_VERSION >= "8.4") {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
      }
      else {
        $this->parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
      }
    }
    else {
      $this->parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP5);
    }
  }

  function GetForeachCandidates($cand_foreach) {
    $candidates = array();
    foreach ($cand_foreach as $prop_name => $cand_array) {
      foreach ($cand_array as $cand_info) {
        if (!array_key_exists($prop_name, $candidates)) {
          $candidates[$prop_name] = array();
        }

        if (!array_key_exists($cand_info->method, $candidates[$prop_name])) {
          $candidates[$prop_name][$cand_info->method] = array();
        }

        $info = new stdClass;
        $info->class = $cand_info->class;
        $info->real_class = $cand_info->class;
        $info->file = $cand_info->class;
        $info->real_file = $cand_info->class;
        $info->var_list = array();
        $info->class_alias = "";
        $info->prop_list = $cand_info->prop_list;

        $payload_creator = new PayloadCreator();
        $input_tree = $payload_creator->MakeGadgetTemplate($info);

        $template = $payload_creator->MakeAuxiliaryTemplateWithProp(
                      $input_tree, $info, $this->cand_props_hash_table
                    );

        if (!in_array($template, $candidates[$prop_name])) {
          $candidates[$prop_name][$cand_info->method][] = $template;
        }
      }
    }

    return $candidates;
  }

  function GetClassCandidates($cand_methods, $cand_props) {
    $candidates = array();
    foreach ($cand_methods as $method_name => $cands) {
      foreach ($cands as $cand) {
        if (!in_array($cand, $candidates)) {
          $candidates[] = $cand;
        }
      }
    }
    foreach ($cand_props as $prop_name => $cands) {
      foreach ($cands as $cand) {
        if (!in_array($cand, $candidates)) {
          $candidates[] = $cand;
        }
      }
    }
    return $candidates;
  }

  function MakePropCandidatesHT($cand_props) {
    $hash_table = array();

    foreach ($cand_props as $prop_name => $prop_obj) {
      foreach ($prop_obj as $prop_info) {
        $class_name = $prop_info->class;
        if (!array_key_exists($class_name, $hash_table)) {
          $hash_table[$class_name] = array();
        }

        $cand_prop = array();

        $cand_prop['name'] = $prop_name;
        $cand_prop['value'] = "";
        $cand_prop['file'] = $prop_info->file;
        $cand_prop['visibility'] = $prop_info->visibility;
        $cand_prop['type'] = "Unknown";
        $cand_prop['deps'] = array();

        $cand_prop['info'] = new stdClass;
        $cand_prop['info']->data = new stdClass;
        $cand_prop['info']->data->name = $prop_name;
        $cand_prop['info']->data->visibility = $prop_info->visibility;
        $cand_prop['info']->data->class = $class_name;
        $cand_prop['info']->data->real_class = $prop_info->class;
        $cand_prop['info']->data->file = $prop_info->file;
        $cand_prop['info']->data->real_file = $prop_info->file;
        $cand_prop['info']->data->parents = array();
        $cand_prop['info']->data->parents[0] = new stdClass;
        $cand_prop['info']->data->parents[0]->TYPE = "CLASS";
        $cand_prop['info']->data->parents[0]->NAME = $prop_name;
        $cand_prop['info']->data->parents[0]->FILE = $prop_info->file;

        $cand_prop['info']->candidates = array();
        $cand_prop['info']->candidates[0] = new stdClass;
        $cand_prop['info']->candidates[0]->type = "Unknown";
        $cand_prop['info']->candidates[0]->class = new stdClass;
        $cand_prop['info']->candidates[0]->class->file = $prop_info->file;
        $cand_prop['info']->candidates[0]->class->class = $class_name;
        $cand_prop['info']->candidates[0]->visibility = $prop_info->visibility;
        $cand_prop['info']->candidates[0]->deterministic = false;

        array_push($hash_table[$class_name], $cand_prop);
      }
    }
    return $hash_table;
  }

  function SendResultToProxy($message) {
    $result_channel = $this->rabbitmq_settings['channel'] . "_result";

    $result_msg = $message;

    $channel = $this->rabbitmq_connection->channel();
    $channel->queue_declare($result_channel, false, false, false, true);
    $channel->basic_publish(new AMQPMessage($result_msg), '', $result_channel);
    return true;
  }

  function IsNeedRevise($copied_seed) {
    $fuzz_classified = $copied_seed->fuzz_result['result']['case'];
    $judge_revise = array("result" => false, "type" => "");

    if ($fuzz_classified['case'] == "ERROR" and
        $fuzz_classified['msg']['type'] == "NON_OBJECT_METHOD") {
      $judge_revise = array("result" => true, "type" => "NON_OBJECT_METHOD");
    }

    return $judge_revise;
  }

  function MakeAddPropSeeds($copied_seed) {
    $new_prop = array();

    $fuzz_classified = $copied_seed->fuzz_result['result']['case'];
    $call_failed_func = explode("(", $fuzz_classified['msg']['contents'])[0];
    $last_called = end($copied_seed->fuzz_result['result']['branchHit']);
    $last_called_hash = $last_called['hash'];
    $inssuf_prop = $fuzz_classified['msg']['class_invoke'][$last_called_hash];

    $prop_ast = $this->parser->parse("<?php\n" . $inssuf_prop['prop'] . ";");
    $prop_string = $prop_ast[0]->name;

    if (!property_exists($this->cand_methods, $call_failed_func)) {
      return False;
    }

    // [FIXME] Right? $this->cand_methods[$call_failed_func]?
    $missing_method_info = $this->cand_methods->$call_failed_func;

    $new_method_info_list = array();
    foreach ($missing_method_info as $method_info) {
      $prop_visibility = "public";
      if (!empty($this->cand_props->$prop_string)) {
        foreach ($this->cand_props->$prop_string as $cand_prop) {
          if ($cand_prop->file == $method_info->file and
              $cand_prop->class == $method_info->class) {
            $prop_visibility = $cand_prop->visibility;
            break;
          }
        }
      }

      $new_method_info = new stdClass;
      $new_method_info->type = "Object";
      $new_method_info->value = new stdClass;
      $new_method_info->value->file = $method_info->file;
      $new_method_info->value->class = $method_info->class;
      $new_method_info->visibility = $prop_visibility;
      $new_method_info->deterministic = false;
      array_push($new_method_info_list, $new_method_info);
    }

    $new_prop['name'] = $prop_string;
    $new_prop['value'] = "";
    $new_prop['file'] = "";
    $new_prop['visibility'] = NULL;
    $new_prop['type'] = "Object";
    $new_prop['deps'] = array();

    $new_prop['info'] = new stdClass;
    $new_prop['info']->data = new stdClass;
    $new_prop['info']->data->name = $prop_string;
    $new_prop['info']->data->visibility = NULL;
    $new_prop['info']->data->class = $new_method_info_list[0]->value->class;
    $new_prop['info']->data->real_class = $new_method_info_list[0]->value->class;
    $new_prop['info']->data->file = $new_method_info_list[0]->value->file;
    $new_prop['info']->data->real_file = $new_method_info_list[0]->value->file;
    $new_prop['info']->data->class_alias = "";
    $new_prop['info']->data->parents = array();
    $new_prop['info']->data->parents[0] = new stdClass;
    $new_prop['info']->data->parents[0]->TYPE = "CLASS";
    $new_prop['info']->data->parents[0]->NAME = $new_method_info_list[0]->value->class;
    $new_prop['info']->data->parents[0]->FILE = $new_method_info_list[0]->value->file;
    $new_prop['info']->candidates = $new_method_info_list;

    $prop_template = array();
    $prop_template['$this']['type'] = "Object";
    $prop_template['$this']['value'] = $copied_seed->input_tree['$this']['value'];
    $prop_template['$this']['file'] = NULL;
    $prop_template['$this']['visibility'] = NULL;
    $prop_template['$this']['info'] = $copied_seed->input_tree['$this']['info'];
    $prop_template['$this']['deps'][$prop_string] = $new_prop;

    return $copied_seed->GetMergedTreeList($prop_template, True);
  }

  function GetConstraints($inst_file, $rabbitmq_settings, $rabbitmq_connection) {
    putenv("RABBITMQ_IP=" . $rabbitmq_settings['ip']);
    putenv("RABBITMQ_PORT=" . $rabbitmq_settings['port']);
    putenv("RABBITMQ_ID=" . $rabbitmq_settings['id']);
    putenv("RABBITMQ_PASSWORD=" . $rabbitmq_settings['password']);
    putenv("RABBITMQ_CHANNEL=" . $rabbitmq_settings['channel']);
    putenv("FUZZ_CMD=FuzzerInit");
    shell_exec("php " . "-d memory_limit=" . MEMORY_LIMIT . " " . $inst_file);
    putenv("RABBITMQ_IP=None");
    putenv("RABBITMQ_PORT=None");
    putenv("RABBITMQ_ID=None");
    putenv("RABBITMQ_PASSWORD=None");
    putenv("RABBITMQ_CHANNEL=None");
    putenv("FUZZ_CMD=None");

    $channel = $rabbitmq_connection->channel();
    $channel->queue_declare($rabbitmq_settings['channel'], false, false, false, true);
    $channel->basic_consume($rabbitmq_settings['channel'], '', false, true, false, false,
                            array($this, 'GetConstraintCallBack'));
    while (count($channel->callbacks)) {
      try {
        $channel->wait(null, false, 10);
      }
      catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
        // Pass
      }
      $channel->close();
    }

    $constraints = unserialize($this->constraints_raw);
    unset($this->constraints_raw); // Garbage Collection
    return $constraints;
  }

  function GetConstraintCallBack($msg) {
    $this->constraints_raw = $msg->body;
  }

  function MakeHintedSeeds($input_seed, $hinting_data, $passed_conds) {
    $IDX_HINTING_TYPE = 0;
    $IDX_PROP_NAME = 1;
    $IDX_HINTING_PROP_TYPE = 2;
    $IDX_HINTING_PROP_VALUE = 3;

    // Counting each prop names.
    $counting_props = array();
    $counting_queue = array($input_seed->input_tree['$this']);
    while (count($counting_queue) != 0) {
      $counting_node = array_shift($counting_queue);
      if ($counting_node['type'] != "Object") {
        if (!array_key_exists($counting_node['name'], $counting_props)) {
          $counting_props[$counting_node['name']] = 0;
        }
        $counting_props[$counting_node['name']] += 1;
      }
      foreach ($counting_node['deps'] as $child_node) {
        $counting_queue[] = $child_node;
      }
    }

    // Colect hinting info and reserve seeds.
    $reserved_hinted_seeds = array();
    $hinted_infos = array();
    foreach ($passed_conds as $passed_cond_hash => $passed_cond_type) {
      if (array_key_exists($passed_cond_hash, $hinting_data['CONDITIONS'])) {
        // Does passeed condition existsed in hinting info?
        $fetched_hinting = $hinting_data['CONDITIONS'][$passed_cond_hash];
        $hint_type = $fetched_hinting[$IDX_HINTING_TYPE];
        $prop_name = $fetched_hinting[$IDX_PROP_NAME];
        $hinting_info = array(
          "hint_type" => $hint_type,
          "prop_name" => $prop_name,
          "prop_type" => $fetched_hinting[$IDX_HINTING_PROP_TYPE],
          "prop_value" => $fetched_hinting[$IDX_HINTING_PROP_VALUE]
        );
        if (!array_key_exists($prop_name, $this->hinting_infos)) {
          $this->hinting_infos[$prop_name] = array();
        }
        if (!in_array($hinting_info, $this->hinting_infos[$prop_name])) {
          $this->hinting_infos[$prop_name][] = $hinting_info;
        }
        if (array_key_exists($prop_name, $hinted_infos)) {
          if (array_key_exists($hint_type, $hinted_infos[$prop_name])) {
            if (in_array($hinting_info, $hinted_infos[$prop_name][$hint_type])) {
              // Does prop already in hinted list?
              continue;
            }
          }
          else {
            $hinted_infos[$prop_name][$hint_type] = array();
          }
        }
        else {
          $hinted_infos[$prop_name] = array();
          $hinted_infos[$prop_name][$hint_type] = array();
        }

        // Reserve seeds as prop count.
        if (array_key_exists($prop_name, $counting_props)) {
          for ($prop_idx = 0; $prop_idx < $counting_props[$prop_name]; $prop_idx++) {
            $reserved_hinted_seed = array("seed" => clone $input_seed,
                                          "change_info" => $hinting_info,
                                          "order" => $prop_idx);
            array_push($reserved_hinted_seeds, $reserved_hinted_seed);
          }
        }
        array_push($hinted_infos[$prop_name][$hint_type], $hinting_info);
      }
    }

    // var_dump($hinted_infos);
    // var_dump($cloned_seeds);
    $hinted_seeds = array();
    while ($hinted_seed = &$reserved_hinted_seeds[0]) {
      array_shift($reserved_hinted_seeds);
      $hinted_seed_change_info = $hinted_seed['change_info'];
      $pass_count = $hinted_seed['order'];
      $tree_queue = array();
      $tree_queue[] = &$hinted_seed['seed']->input_tree['$this'];

      while ($node = &$tree_queue[0]) {
        array_shift($tree_queue);
        if ($node['type'] != "Object") {
          if ($node['name'] == $hinted_seed_change_info['prop_name']) {
            // Order check.
            if ($pass_count > 0) {
              $pass_count -= 1;
              continue;
            }

            if ($hinted_seed_change_info['hint_type'] == "ONLY_TYPE") {
              $node['type'] = $hinted_seed_change_info['prop_type'];
            }
            elseif ($hinted_seed_change_info['hint_type'] == "TYPE_VALUE") {
              $node['type'] = $hinted_seed_change_info['prop_type'];
              $node['value'] = $hinted_seed_change_info['prop_value'];
              if ($node['type'] == "Boolean") {
                if ($hinted_seed_change_info['prop_value'] == "true") {
                  $node['value'] = true;
                }
                elseif ($hinted_seed_change_info['prop_value'] == "false") {
                  $node['value'] = false;
                }
              }
            }
            $node['preserve'] = $hinted_seed_change_info['hint_type'];
            break;
          }
        }
        foreach ($node['deps'] as &$child_node) {
          $tree_queue[] = &$child_node;
        }
      }
      array_push($hinted_seeds, $hinted_seed['seed']);

      // Reference clear
      unset($tree_queue);
      unset($child_node);
      unset($node);
    }
    return $hinted_seeds;
  }

  function MakeArrayHintedSeed($input_seed, $array_fetch_list) {
    $arr_datas = array(); // dim

    foreach ($array_fetch_list as $array_fetch_infos) {
      foreach ($array_fetch_infos as $fetch_hash =>$array_fetch_info) {
        $arr_data = array(
          "hash" => $fetch_hash,
          "var_string" => $array_fetch_info['var_string'],
          "dim_string" => $array_fetch_info['dim_string'],
          "var" => $array_fetch_info['var'],
          "dim" => $array_fetch_info['dim'],
        );
        array_push($arr_datas, $arr_data);
      }
    }

    if (count($arr_datas) == 0) {
      return False;
    }
    else {
      $array_hinted_seed = clone $input_seed;
      $array_hinted_seed->array_hinting = $arr_datas;
      return $array_hinted_seed;
    }
  }

  function RunFuzz(&$seed_pool) {
    $this->seed_pool = $seed_pool;
    $total_exec_count = 0;
    $start_timestamp = time() + 3600 * 9;
    $start_time = date("Y-m-d H:i:s", $start_timestamp);

    while (True) {
      $current_timestamp = time() + 3600 * 9;

      // Timeout
      if ($current_timestamp - $start_timestamp > $GLOBALS['FUZZING_TIMEOUT']) {
        $debug = new DebugUtils;
        $debug->PrintSelectedLog($this->seed_selected_log);

        $result = array(
          "result" => "FINISH",
          "chain" => $this->file_chain,
          "message" => array(
            "SEED_VALUE" => $GLOBALS['SEED_VALUE'],
            "info" => NULL
          )
        );
        $this->SendResultToProxy(json_encode($result));
        echo "[-] Fuzzing Timeout! (" . $GLOBALS['FUZZING_TIMEOUT'] ." sec)\n";
        echo "[#] Seed Value: " . $GLOBALS['SEED_VALUE'] . "\n";
        exit();
      }

      $total_exec_count += 1;

      // First time: set root seed
      if ($this->seed_pool->root == NULL) {
        $this->seed_pool->SetRoot($this->GenerateFirstSeed());
      }

      // Select seed using seed selection algorithm
      $selected_seed = $this->seed_pool->CherryPick(count($this->chain));
      if (empty($this->seed_selected_log[$selected_seed->seed_idx])) {
        $this->seed_selected_log[$selected_seed->seed_idx]['count'] = 0;
        $this->seed_selected_log[$selected_seed->seed_idx]['depth'] = $selected_seed->depth;
        $this->seed_selected_log[$selected_seed->seed_idx]['goal_depth'] = $selected_seed->goal_depth;
        $this->seed_selected_log[$selected_seed->seed_idx]['sink_reached'] = $selected_seed->sink_reached['reach'];
        $this->seed_selected_log[$selected_seed->seed_idx]['removed'] = False;
      }
      $this->seed_selected_log[$selected_seed->seed_idx]['count']++;

      // Copy seed
      $payload_creator = new PayloadCreator();
      $copied_seed = new SeedNode();
      $copied_seed->parent = $selected_seed;
      $copied_seed->seed_idx = $this->seed_pool->seed_idx;
      $this->seed_pool->seed_idx += 1;

      $copied_seed->goal_depth = $selected_seed->goal_depth;
      $copied_seed->depth = $selected_seed->depth;
      $copied_seed->class_template = $selected_seed->class_template;
      $copied_seed->input_tree = $selected_seed->input_tree;
      $copied_seed->sink_reached = $selected_seed->sink_reached;
      $copied_seed->sink_strategy_tried = $selected_seed->sink_strategy_tried;
      $copied_seed->need_validating_argv_idxs = $selected_seed->need_validating_argv_idxs;
      $copied_seed->validated_argv_idxs = $selected_seed->validated_argv_idxs;
      $copied_seed->array_hinting = $selected_seed->array_hinting;
      $copied_seed->array_object = $selected_seed->array_object;

      // If sink reached seed -> Oracle
      if ($copied_seed->sink_reached['reach'] == True) {
        $this->oracle_exec_count++;
        $sink_gadget = end($this->chain);
        $sink_name = $sink_gadget->sink;
        $sink_type = $sink_gadget->type; // FuncCall, MethodCall

        $sink_info = GetSinkInfo($sink_name, $sink_type);
        if (count($sink_info) != 1) { // Duplicated on sink config!
          echo "[!] " . $sink_name ." (" . $sink_type .") " . "is duplicated!";
          exit();
        }

        $sink_tree = $payload_creator->SetSinkProperties(
                                        $copied_seed->input_tree,
                                        $copied_seed->parent->sink_reached,
                                        $sink_info[0],
                                        $this->file_chain,
                                        $copied_seed->sink_strategy_tried,
                                        $copied_seed->validated_argv_idxs
                                      );

        $selected_seed->sink_strategy_tried = $sink_tree['strategy'];
        $copied_seed->sink_strategy_tried = $sink_tree['strategy'];
        if ($selected_seed->need_validating_argv_idxs == array()) {
          // First oracle trying.
          $selected_seed->need_validating_argv_idxs = $sink_tree['require_idx_list'];
          $copied_seed->need_validating_argv_idxs = $sink_tree['require_idx_list'];
        }

        if ($sink_tree['all_tried']) {
          // Cannot manipulate sink argv!
          $this->seed_pool->RemoveSeed($selected_seed);
          $this->seed_selected_log[$selected_seed->seed_idx]['removed'] = True;
          continue;
        }

        if ($sink_tree['prop_changed']['changed'] == False and
            $sink_tree['prop_changed']['usable_default'] == False) {
          // Select prop failed..
          continue;
        }

        $copied_seed->input_tree = $sink_tree['input_tree'];
        $executor = new Executor();
        $copied_seed->fuzz_result = $executor->ExecutePutByPayloadTree(
                                      $this->file_inst, $this->file_chain,
                                      $copied_seed->input_tree,
                                      $this->rabbitmq_settings,
                                      $this->rabbitmq_connection,
                                      $this->chain[0]->method,
                                      $this->chain
                                    );

        $file_payload_oracle = $copied_seed->fuzz_result['file_output'];

        $analyzed_result = $executor->AnalyzeExecutedResult(
                            $copied_seed->fuzz_result['result'],
                            $this->chain,
                            $copied_seed
                          );

        $copied_seed->depth = count($analyzed_result['gadget_pass_check']);
        $copied_seed->goal_depth = count($this->chain) - 1; // Basically.. Goal is sink reach due to oracle.

        if ($analyzed_result['sink_executed']) {
          foreach ($analyzed_result['sink_branch'] as
                    $oracle_sink_exec_idx => $sink_exec_log) {
            $oracle_success = False;
            $sink_validating_argvs = array();
            if (array_key_exists("ANY", $sink_info[0]['argvs'])) {
              foreach (array_keys($sink_exec_log['argvs']) as $sink_argv_idx) {
                array_push($sink_validating_argvs, $sink_argv_idx);
              }
              $goal_datas = $sink_info[0]['argvs']['ANY'];
            }
            else {
              if ($oracle_sink_exec_idx != $sink_tree['prop_changed']['sink_exec_idx']) {
                continue;
              }
              array_push($sink_validating_argvs,
                          $sink_tree['prop_changed']['sink_argv_idx']);
              $goal_datas = $sink_info[0]['argvs'][$sink_tree['prop_changed']['sink_argv_idx']];
            }

            $goal_data = $goal_datas['ARGV_CAND'][$sink_tree['prop_changed']['sink_goal_idx']];
            $goal_value = $payload_creator->GetGoalValue(
                            $goal_data['Goal'],
                            $goal_data['GoalType'],
                            $this->file_chain
                          );
            $goal_var = $payload_creator->VarExport($goal_value);

            // Removing string quote in goal arguments
            $goal_var_for_validating = $payload_creator->VarUnQuote($goal_var);

            foreach ($sink_validating_argvs as $sink_validating_argv_idx) {
              if (array_key_exists($sink_validating_argv_idx,
                                    $sink_exec_log['argvs']) == False and
                  $sink_tree['prop_changed']['usable_default'] == True) {
                $oracle_sink_idx = $sink_tree['prop_changed']['sink_exec_idx'];
                $oracle_argv_idx = $sink_tree['prop_changed']['sink_argv_idx'];
                if (!array_key_exists($oracle_sink_idx,
                                      $selected_seed->validated_argv_idxs)) {
                  $selected_seed->validated_argv_idxs[$oracle_sink_idx] = array();
                }
                $selected_seed->validated_argv_idxs[$oracle_sink_idx][$oracle_argv_idx] = $goal_var;

                if (!array_key_exists($oracle_sink_idx,
                                      $copied_seed->validated_argv_idxs)) {
                  $copied_seed->validated_argv_idxs[$oracle_sink_idx] = array();
                }
                $copied_seed->validated_argv_idxs[$oracle_sink_idx][$oracle_argv_idx] = $goal_var;

                $selected_seed->input_tree = $sink_tree['input_tree'];
                if (count($copied_seed->validated_argv_idxs) ==
                    count($sink_tree['require_idx_list'])) {
                  $oracle_success = True;
                }
                break;
              }
              $sink_value = $sink_exec_log['argvs'][$sink_validating_argv_idx];
              $sink_var = $payload_creator->VarExport($sink_value);

              // Removing string quote in sink arguments
              $sink_var_for_validating = $payload_creator->VarUnQuote($sink_var);

              // Do not strpos in null value
              if ($sink_var_for_validating == "" ) {
                continue;
              }

              if ($goal_data['ValidType'] == "Include") {
                if (strpos($sink_var_for_validating,
                            $goal_var_for_validating) !== False) {
                  if (strpos($sink_var, "__PHP_Incomplete_Class::__set_state") !== 0) {
                    eval('$goal_var_type = gettype(' . $goal_var . ");");
                    eval('$sink_var_type = gettype(' . $sink_var . ");");
                    if ($goal_var_type == $sink_var_type) {
                      $oracle_sink_idx = $sink_tree['prop_changed']['sink_exec_idx'];
                      $oracle_argv_idx = $sink_tree['prop_changed']['sink_argv_idx'];
                      if (!array_key_exists($oracle_sink_idx,
                                            $selected_seed->validated_argv_idxs)) {
                        $selected_seed->validated_argv_idxs[$oracle_sink_idx] = array();
                      }
                      $selected_seed->validated_argv_idxs[$oracle_sink_idx][$oracle_argv_idx] = $goal_var;

                      if (!array_key_exists($oracle_sink_idx,
                                            $copied_seed->validated_argv_idxs)) {
                        $copied_seed->validated_argv_idxs[$oracle_sink_idx] = array();
                      }
                      $copied_seed->validated_argv_idxs[$oracle_sink_idx][$oracle_argv_idx] = $goal_var;

                      $selected_seed->input_tree = $sink_tree['input_tree'];
                      if (count($copied_seed->validated_argv_idxs[$oracle_sink_idx]) ==
                          count($sink_tree['require_idx_list'])) {
                        $oracle_success = True;
                      }
                      break;
                    }
                  }
                }
              }
            }
            if ($oracle_success) {
              break;
            }
          }
        }

        $debug = new DebugUtils();
        $debug->PrintFuzzingStat(
          $selected_seed, $copied_seed,
          $start_timestamp, $start_time, $total_exec_count,
          $this->seed_pool->GetSeedCount(), $this->probably_exploitble_count,
          $this->oracle_exec_count, $this->chain,
          False
        );

        if ($oracle_success) {
          $debug->PrintSelectedLog($this->seed_selected_log);
          // var_dump($copied_seed->sink_strategy_tried);

          $sink_output_head = "<?php\n";
          $sink_output_head .= "namespace POINT_RESULT{\n";
          $sink_output_tail = "}\n";
          $sink_output_tail .= "?>\n";

          echo "[#] Oracle Success\n";
          $sink_output = "[#] Automatic Exploit Generation (AEG) by POINT (Result)\n";
          $sink_output .= "[#] Sink function: " . end($this->chain)->sink . "()\n";
          foreach ($sink_exec_log['argvs'] as $argv_idx => $sink_argv) {
            $sink_output .= "  [+] Argv" . $argv_idx  . ": " . var_export($sink_argv, true) . "\n";
          }
          echo $sink_output;
          $sink_output = "/* ---------------------------------------------- \n" .
                          $sink_output;
          $sink_output .= "------------------------------------------------- */\n";
          $sink_output = $sink_output_head . $sink_output . $sink_output_tail;

          if (!file_exists(dirname(realpath($this->file_inst)) . "/EXPLOITABLE")) {
            mkdir(dirname(realpath($this->file_inst)) . "/EXPLOITABLE");
          }

          $file_success_output = dirname(realpath($this->file_inst)) .
                                  "/EXPLOITABLE/" . basename($file_payload_oracle) .
                                  "_" . $GLOBALS['SEED_VALUE'] .
                                  "_" . end($this->chain)->sink;

          $e_org_contents = file_get_contents($file_payload_oracle);
          file_put_contents($file_success_output, $sink_output . $e_org_contents);

          echo "[#] Fuzzing success: " . basename($file_success_output) . "\n";
          echo "[#] Seed Value: ". $GLOBALS['SEED_VALUE'] . "\n";

          $result = array(
            "result" => "EXPLOITABLE",
            "chain" => $this->file_chain,
            "message" => array(
                "SEED_VALUE" => $GLOBALS['SEED_VALUE'],
                "info" => NULL
            )
          );

          $this->SendResultToProxy(json_encode($result));
          break;
        }
        else {
          if ($sink_tree['all_tried']) {
              var_dump($copied_seed->sink_strategy_tried);
              var_dump($copied_seed->parent->sink_reached['sink_branch']);
              exit("[-] Oracle Failed! - Debug Plz.");
          }
        }

        unlink($copied_seed->fuzz_result['file_output']);
        continue;
      }

      // Add new properties
      $copied_seed->input_tree = $payload_creator->MakeAuxiliaryTemplateWithProp(
                                  $copied_seed->input_tree,
                                  $this->gadget[$selected_seed->depth + 1],
                                  $this->cand_props_hash_table
                                 );

      // Mutate properties
      $copied_seed->input_tree = $payload_creator->SetMutateProperties(
                                  $copied_seed->input_tree,
                                  $this->file_chain, $this->gadget,
                                  $this->cand_class, $this->cand_foreach,
                                  $this->hinting_infos,
                                  $copied_seed->array_hinting,
                                  $copied_seed->array_object
                                 );

      $debug = new DebugUtils();
      // $debug->PrintTemplateInfo($selected_seed, $this->chain);
      // $debug->PrintTemplateInfo($copied_seed, $this->chain);

      $executor = new Executor();
      $copied_seed->fuzz_result = $executor->ExecutePutByPayloadTree(
                                    $this->file_inst, $this->file_chain,
                                    $copied_seed->input_tree,
                                    $this->rabbitmq_settings,
                                    $this->rabbitmq_connection,
                                    $this->chain[0]->method,
                                    $this->chain
                                  );

      // var_dump($copied_seed->fuzz_result);
      // foreach($copied_seed->fuzz_result['result']['branchHit'] as $branch)
      //   echo $branch['type'] . "\n";
      // var_dump($copied_seed->fuzz_result['result']['branchHit']);
      // var_dump($copied_seed->fuzz_result['result']['debug_message']);
      // exit();
      if ($copied_seed->fuzz_result['status'] == FALSE) {
        /* Catch memory size over error as follows. (Reentracy)
        class cls_a{
            public $prop_1;
            function __construct(){
                $this->prop_1 = new cls_a;
            }
        }
        // TODO: These case should not be execute again.
        */
        continue;
      }

      $copied_seed->path_hash = $this->GetBranchHitHash(
                                  $copied_seed->fuzz_result['result']
                                );
      $revise_check = $this->IsNeedRevise($copied_seed);
      if ($revise_check['result']) {
        if ($revise_check['type'] == "NON_OBJECT_METHOD") {
          $added_prop_seeds = $this->MakeAddPropSeeds($copied_seed);
          if ($added_prop_seeds != false) {
            foreach ($added_prop_seeds as $added_prop_seed) {
              $new_added_seed = new SeedNode();
              $new_added_seed->template_tree = $added_prop_seed;
              $new_added_seed->input_tree = $payload_creator->SetMutateProperties(
                                              $new_added_seed->template_tree,
                                              $this->file_chain, $this->gadget,
                                              $this->cand_class, $this->cand_foreach,
                                              $this->hinting_infos,
                                              $new_added_seed->array_hinting,
                                              $copied_seed->array_object
                                            );
              $new_added_seed->goal_depth = $copied_seed->depth + 1;
              $new_added_seed->depth = $copied_seed->depth;
              $new_added_seed->select_count = 0;
              $new_added_seed->seed_idx = $this->seed_pool->seed_idx;
              $this->seed_pool->seed_idx += 1;
              // Duplicate check [TODO - NEED TO TEST]
              $this->seed_pool->AddSeed($selected_seed, $new_added_seed, True);
            }
          }
        }
      }

      if ($this->seed_pool->IsNewPath($copied_seed)) {
        $analyzed_result = $executor->AnalyzeExecutedResult(
                            $copied_seed->fuzz_result['result'],
                            $this->chain,
                            $copied_seed
                           );
        // var_dump($analyzed_result);

        $file_payload = $copied_seed->fuzz_result['file_output'];
        if ($analyzed_result['sink_executed'] == True) {
          $copied_seed->sink_reached['reach'] = True;
          $copied_seed->sink_reached['sink_branch'] = $analyzed_result['sink_branch'];
          $copied_seed->depth = count($analyzed_result['gadget_pass_check']);
          $copied_seed->goal_depth = count($analyzed_result['gadget_pass_check']);

          // No Duplicate check. because this seed reached to sink.
          $this->seed_pool->AddSeed($selected_seed, $copied_seed);

          if ($this->probably_exploitble_count == 0) {
            $result = array(
              "result" => "PROBABLY_EXPLOITABLE",
              "chain" => $this->file_chain,
              "message" => array(
                  "SEED_VALUE" => $GLOBALS['SEED_VALUE'],
                  "info" => NULL
              )
            );

            $this->SendResultToProxy(json_encode($result));
          }

          if (!file_exists(dirname(realpath($this->file_inst)) . "/PROBABLY_EXPLOITABLE")) {
            mkdir(dirname(realpath($this->file_inst)) . "/PROBABLY_EXPLOITABLE");
          }

          $file_reached_output = dirname(realpath($this->file_inst)) .
                                  "/PROBABLY_EXPLOITABLE/" . basename($file_payload) .
                                  "_" . $GLOBALS['SEED_VALUE'] .
                                  "_" . end($this->chain)->sink;

          $this->probably_exploitble_count++;
          $debug = new DebugUtils();
          $debug->PrintFuzzingStat(
            $selected_seed, $copied_seed,
            $start_timestamp, $start_time, $total_exec_count,
            $this->seed_pool->GetSeedCount(), $this->probably_exploitble_count,
            $this->oracle_exec_count, $this->chain,
            False
          );

          $sink_gadget = end($this->chain);
          $sink_name = $sink_gadget->sink;
          $sink_type = $sink_gadget->type;
          $sink_info = GetSinkInfo($sink_name, $sink_type);

          $sink_output = "[#] Probably Exploitable Report by POINT (Result)\n";
          $sink_output .= "[#] Sink function: " . end($this->chain)->sink . "()\n";
          foreach ($analyzed_result['sink_branch'] as $sink_exec_idx => $sink_exec_log) {
            $sink_output .= "[+] Exec #" . ($sink_exec_idx + 1) . "\n";
            foreach ($sink_exec_log['argvs'] as $argv_idx => $sink_argv) {
              $sink_output .= "  [+] Argv" . $argv_idx  . ": " . var_export($sink_argv, true) . "\n";
            }
          }
          $sink_output_head = "<?php\n";
          $sink_output_head .= "namespace POINT_RESULT{\n";
          $sink_output_tail = "}\n";
          $sink_output_tail .= "?>\n";

          if (array_key_exists("max_pe", $sink_info[0]) and
              $sink_info[0]['max_pe'] == True) {
            echo "[!] Sink reached, But it cannot be determined whether it is PE or E\n";
            echo $sink_output;
            $sink_output = "/* ---------------------------------------------- \n" . $sink_output;
            $sink_output .= "------------------------------------------------- */\n";
            $sink_output = $sink_output_head . $sink_output . $sink_output_tail;
            echo "[#] PE Report: " . basename($file_reached_output) . "\n";
            echo "[#] Seed Value: ". $GLOBALS['SEED_VALUE'] . "\n";
            $pe_org_contents = file_get_contents($file_payload);
            file_put_contents($file_reached_output, $sink_output . $pe_org_contents);
            break; // Exit the fuzzer.
          }
          else {
            $sink_output = "/* ---------------------------------------------- \n" . $sink_output;
            $sink_output .= "------------------------------------------------- */\n";
            $sink_output = $sink_output_head . $sink_output . $sink_output_tail;
            $pe_org_contents = file_get_contents($file_payload);
            file_put_contents($file_reached_output, $sink_output . $pe_org_contents);
            continue; // Fuzzer will be execute payload oracle.
          }
        }

        $copied_seed->depth = count($analyzed_result['gadget_pass_check']) - 1;
        $copied_seed->select_count = 1;

        $this->seed_pool->AddSeed($selected_seed, $copied_seed, True);

        $hinted_seeds = $this->MakeHintedSeeds($copied_seed,
                                                $this->hinting_data,
                                                $analyzed_result['passed_conds']);
        foreach ($hinted_seeds as $hinted_seed) {
          // $hinted_seed->path_hash = NULL;
          $hinted_seed->select_count = 0;
          $this->seed_pool->seed_idx += 1;
          $hinted_seed->seed_idx = $this->seed_pool->seed_idx;
          // Duplicate Check
          $this->seed_pool->AddSeed($selected_seed, $hinted_seed, True);
          // $hinted_seed->depth = count($analyzed_result['gadget_pass_check']) - 1;
          // $hinted_seed->goal_depth = count($analyzed_result['gadget_pass_check']);
        }

        // Hinting 2 (ArrayFetch)
        $array_hinted_seed = $this->MakeArrayHintedSeed(
                                      $copied_seed, $analyzed_result['array_fetch_list']);
        if ($array_hinted_seed != False) {
          $array_hinted_seed->select_count = 0;
          $this->seed_pool->seed_idx += 1;
          $array_hinted_seed->seed_idx = $this->seed_pool->seed_idx;
          $this->seed_pool->AddSeed($selected_seed, $array_hinted_seed, True);
        }

        if ($copied_seed->depth > $selected_seed->depth) {
          if ($copied_seed->depth == 0 and $selected_seed->depth == -1) {
            $next_gadget_index = $copied_seed->depth + 1;
          }
          else {
            $next_gadget_index = $copied_seed->depth;
          }
          $next_gadget = $payload_creator->MakeGadgetTemplate(
                                            $this->gadget[$next_gadget_index]);
          $merged_tree_list = $copied_seed->GetMergedTreeList($next_gadget);

          foreach ($merged_tree_list as $merged_tree) {
            $new_evolved_seed = new SeedNode();
            $new_evolved_seed->template_tree = $merged_tree;
            $new_evolved_seed->input_tree = $payload_creator->SetMutateProperties(
                                              $new_evolved_seed->template_tree,
                                              $this->file_chain, $this->gadget,
                                              $this->cand_class, $this->cand_foreach,
                                              $this->hinting_infos,
                                              $new_evolved_seed->array_hinting,
                                              $next_gadget
                                            );
            $new_evolved_seed->goal_depth = $copied_seed->depth + 1;
            $new_evolved_seed->depth = $copied_seed->depth;
            $new_evolved_seed->select_count = 0;
            $new_evolved_seed->seed_idx = $this->seed_pool->seed_idx;
            $new_evolved_seed->array_object = $next_gadget;
            $this->seed_pool->seed_idx += 1;
            $this->seed_pool->AddSeed($selected_seed, $new_evolved_seed, True);
          }
        }
      }
      else {
        // var_dump($copied_seed->fuzz_result['file_output']);
        unlink($copied_seed->fuzz_result['file_output']);
      }
      // unlink($copied_seed->fuzz_result['file_output']);

      $debug = new DebugUtils();
      $debug->PrintFuzzingStat(
        $selected_seed, $copied_seed,
        $start_timestamp, $start_time, $total_exec_count,
        $this->seed_pool->GetSeedCount(), $this->probably_exploitble_count,
        $this->oracle_exec_count, $this->chain,
        False
      );

      // Temporary Debug Code (Start)
      if (count($copied_seed->fuzz_result['result']['debug_message']) > 0) {
        if (!file_exists(dirname(realpath($this->file_inst)) . "/DEBUG")) {
          mkdir(dirname(realpath($this->file_inst)) . "/DEBUG");
        }
        $dir_debug_output = dirname(realpath($this->file_inst)) . "/DEBUG/";
        $file_debug_output =  $dir_debug_output . basename($file_payload) .
                                "_" . $GLOBALS['SEED_VALUE'] .
                                "_" . end($this->chain)->sink;
        copy($file_payload, $file_debug_output);
        $debug_fp = fopen($dir_debug_output . "debug.txt", "a+");
        $debug_content = array(
          "chain" => $this->file_chain,
          "seed_value" => $GLOBALS['SEED_VALUE'],
          "message" => $copied_seed->fuzz_result['result']['debug_message'],
          "payload_file" => $file_debug_output
        );
        fwrite($debug_fp, json_encode($debug_content) . "\n");
        fclose($debug_fp);

        $result = array(
          "result" => "DEBUG",
          "chain" => $this->file_chain,
          "message" => array(
            "SEED_VALUE" => $GLOBALS['SEED_VALUE'],
            "info" => $copied_seed->fuzz_result['result']['debug_message']
          )
        );
        $this->SendResultToProxy(json_encode($result));

        echo "[!] Need to Debug!!!\n";

        // var_dump($copied_seed->fuzz_result['result']);
        var_dump($copied_seed->fuzz_result['result']['debug_message']);

        // exit();
      }
      // Temporary Debug Code (End)
    }
  }

  function GetBranchHitHash($fuzz_result) {
    $path_hash = "";

    foreach ($fuzz_result['branchHit'] as $branch) {
      $path_hash = md5($path_hash . $branch['hash']);
    }

    return $path_hash;
  }

  function GenerateFirstSeed() {
    $payload_creator = new PayloadCreator();
    $new_seed = new SeedNode();

    $new_seed->class_template = $payload_creator->MakeGadgetTemplate($this->gadget[0]);
    $new_seed->input_tree = $payload_creator->GenerateRandomProperties(
                                                $new_seed->class_template,
                                                $this->gadget[0],
                                                $this->file_chain
                                              );
    $new_seed->parent = NULL;
    $new_seed->goal_depth = 0;
    $new_seed->depth = -1;
    $new_seed->select_count = 0;
    $new_seed->seed_idx = 0;
    $this->seed_pool->seed_idx += 1;

    return $new_seed;
  }

  function RabbitMQInit($rabbitmq_settings) {
    $rabbitmq_connection = new AMQPStreamConnection(
                            $rabbitmq_settings['ip'],
                            $rabbitmq_settings['port'],
                            $rabbitmq_settings['id'],
                            $rabbitmq_settings['password']
                           );
    return $rabbitmq_connection;
  }
}
