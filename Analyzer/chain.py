from . import *
import time
import pika
import json
from multiprocessing import *
from datetime import datetime
from dateutil.relativedelta import relativedelta
import requests
import copy
import re
import random

import sys
cur_dir = os.path.dirname(os.path.realpath(__file__))
parent_dir = os.path.dirname(cur_dir)
sys.path.append(parent_dir)

from Utils import arg

class ChainAnalyzer():
    def __init__(self, target_file_list):
        self.target_file_list = target_file_list
        self.args = arg.parse()
        self.manager = Manager()
        self.lock = self.manager.Lock()
        self.userid = 'fugio'
        self.userpw = 'fugio_password'
        self.connection = None
        self.channel = None
        self.max_chain_len = 9

    def _init_rabbitmq(self, queue_name, delete=True):
        while True:
            try:
                if self.connection is None or self.connection.is_closed:
                    cred = pika.PlainCredentials(self.userid, self.userpw)
                    self.connection = pika.BlockingConnection(
                        pika.ConnectionParameters(
                            host=self.host,
                            credentials=cred,
                        )
                    )
                    self.channel = self.connection.channel()
                elif self.connection.is_open and self.channel.is_closed:
                    self.channel = self.connection.channel()
                if delete:
                    self.channel.queue_declare(queue=queue_name, auto_delete = True)
                else:
                    self.channel.queue_declare(queue=queue_name)
                return self.channel
            except Exception as e:
                continue

    def _get_parents(self, file_name, class_name):
        parents = []
        if class_name:
            key = '{}::{}'.format(file_name, class_name)
            for parent in self.class_hierarchy[key]['PARENTS']:
                if parent not in parents:
                    parents.append(parent)
            for parent in self.class_hierarchy[key]['IMPLEMENTS']:
                if parent not in parents:
                    parents.append(parent)
        return parents

    def _get_sink_function(self, call, file_name, real_file_name,
                            class_name, real_class_name, method_name,
                            real_method_name, taint_list, class_alias, this=False):
        new_sink = []
        call_type = call['TYPE']
        call_name = call['FUNCTION']
        call_args = call['ARGS']
        depth = call['DEPTH']
        idx = call['IDX']
        this = call['THIS']
        order = call['ORDER']

        if call_type == "FuncCall":
            if call_name in SINKS:
                control_arg_list = SINKS[call_name]
                for i, arg_cond_list in enumerate(control_arg_list):
                    taint_flag = False
                    for arg_cond in arg_cond_list:
                        if arg_cond == TAINT:
                            if len(call_args) <= i:
                                continue
                            arg = call_args[i]
                            if arg is not None:
                                if len(taint_list) == 0:
                                    taint_flag = False
                                for taint in taint_list:
                                    if taint in arg:
                                        taint_flag = True
                        elif arg_cond == OPTIONAL:
                            taint_flag = True
                        else: #regex
                            if len(call_args) <= i:
                                continue
                            arg = call_args[i]
                            if arg is not None:
                                if re.search(arg_cond, arg):
                                    taint_flag = True
                        if taint_flag:
                            break
                    if taint_flag == False:
                        return new_sink
                new_sink.append({'DATA': {'file': file_name,
                                          'real_file': real_file_name,
                                          'class': class_name,
                                          'real_class': real_class_name,
                                          'class_alias': class_alias,
                                          'method': method_name,
                                          'real_method': real_method_name,
                                          'sink': call_name,
                                          'type': call_type,
                                          'args': call['ARGS']},
                                 'POS': {'depth': depth,
                                         'idx': idx,
                                         'this': this,
                                         'order': order},
                                 'PARENTS': self._get_parents(
                                             file_name, class_name)})
        return new_sink

        # # Check variables in arguments
        # if len(taint_list) == 0:
        #     taint_flag = False

        # elif this:
        #     taint_flag = False
        #     for arg in call_args:
        #         if arg is not None:
        #             for taint, taint_info_list in taint_list.items():
        #                 if taint in arg:
        #                     for taint_info in taint_info_list:
        #                         if taint_info['TYPE'] == 'PROP':
        #                             taint_flag = True
        # else:
        #     taint_flag = False
        #     for arg in call_args:
        #         if arg is not None:
        #             for taint in taint_list:
        #                 if taint in arg:
        #                     taint_flag = True

        # #print (taint_flag)

        # if taint_flag == False:
        #     return new_sink

        # # Find a sink function
        # if call_type == "FuncCall":
        #     if call_name in SINKS:
        #         new_sink.append({'DATA': {'file': file_name,
        #                                   'real_file': real_file_name,
        #                                   'class': class_name,
        #                                   'real_class': real_class_name,
        #                                   'method': method_name,
        #                                   'real_method': real_method_name,
        #                                   'sink': call_name,
        #                                   'type': call_type,
        #                                   'args': call['ARGS']},
        #                          'POS': {'depth': depth,
        #                                  'idx': idx,
        #                                  'this': this,
        #                                  'order': order},
        #                          'PARENTS': self._get_parents(
        #                                      file_name, class_name)})

        # elif call_type == "MethodCall":
        #     if call_name in METHOD_SINKS:
        #         new_sink.append({'DATA': {'file': file_name,
        #                                   'real_file': real_file_name,
        #                                   'class': class_name,
        #                                   'real_class': real_class_name,
        #                                   'method': method_name,
        #                                   'real_method': real_method_name,
        #                                   'sink': call_name,
        #                                   'type': call_type,
        #                                   'args': call['ARGS']},
        #                          'POS': {'depth': depth,
        #                                  'idx': idx,
        #                                  'this': this,
        #                                  'order': order},
        #                          'PARENTS': self._get_parents(
        #                                     file_name, class_name)})

        # # if len(new_sink) > 0:
        # #     print ('[!] Find a sink function\n' + \
        # #             '- File: {}\n'.format(new_sink['file']) + \
        # #             '- Real File: {}\n'.format(new_sink['real_file']) + \
        # #             '- Class: {}\n'.format(new_sink['class']) + \
        # #             '- Real Class: {}\n'.format(new_sink['real_class']) + \
        # #             '- Method: {}\n'.format(new_sink['method']) + \
        # #             '- Sink: {}\n'.format(new_sink['sink']) + \
        # #             '- Type: {}\n'.format(new_sink['type']) + \
        # #             '- Args: {}\n'.format(new_sink['args']))
        # return new_sink

    def _find_sink_functions(self, target_class, target_function, class_alias_list):
        sink_func_list = []
        sink_func_cnt = {}

        for func_name, func_info in target_function.items():
            file_name = func_info['FILE_NAME']

            _file = self.target_file_list[file_name]
            _func = _file.func_list[func_name]
            taint_list = _func.taint_list

            for call in _func.call_list:
                call_name = call['FUNCTION']
                sink_func_list += self._get_sink_function(call,
                                                          file_name, file_name,
                                                          '', '',
                                                          func_name, func_name,
                                                          taint_list, '')

        for class_name, class_info in target_class.items():
            file_name = class_info['FILE_NAME']

            _file = self.target_file_list[file_name]
            _class = _file.class_list[class_name]
            for method_name, method_info in _class.method_list.items():
                real_file_name = method_info.real_file
                real_class_name = method_info.real_class
                real_method_name = method_info.real_name
                if class_name in class_alias_list:
                    class_alias = class_alias_list[class_name]
                else:
                    class_alias = ''

                if real_file_name and real_file_name != INTERNAL:
                    _real_file = self.target_file_list[real_file_name]
                    _real_class = _real_file.class_list[real_class_name]
                    real_method_info = _real_class.method_list[real_method_name]
                    call_list = real_method_info.call_list
                    taint_list = real_method_info.taint_list
                else:
                    call_list = method_info.call_list
                    taint_list = method_info.taint_list

                for call in call_list:
                    call_name = call['FUNCTION']
                    sink_func_list += self._get_sink_function(call,
                                                              file_name,
                                                              real_file_name,
                                                              class_name,
                                                              real_class_name,
                                                              method_name,
                                                              real_method_name,
                                                              taint_list,
                                                              class_alias)

        for sink in sink_func_list:
            sink_name = sink['DATA']['sink']
            if sink_name in sink_func_cnt:
                sink_func_cnt[sink_name] += 1
            else:
                sink_func_cnt[sink_name] = 1

        print ('Total # of sink functions: {}'.format(len(sink_func_list)))

        return sink_func_list

    def _process_function(self, method_list, idx, func_name, func_info,
                          target_class, target_function, class_alias_list):
        file_name = func_info['FILE_NAME']
        _file = self.target_file_list[file_name]
        _func = _file.func_list[func_name]

        method = {'file': file_name,
                  'real_file': file_name,
                  'class': '',
                  'real_class': '',
                  'class_alias': '',
                  'method': func_name,
                  'real_method': func_name}
        callees, func_callees = self._get_callees(method, target_class, target_function, class_alias_list)

        method_list[json.dumps(method)] = {'INFO': method,
                                           'CALLEES': callees,
                                           'FUNC_CALLEES': func_callees}
        # print('[*] Function {}/{}'.format(idx, len(target_function)))
        # print('  [+] File: {}'.format(file_name))
        # print('  [+] Function: {}'.format(func_name))

    def _process_class(self, method_list, magic_method_list, iter_list, access_list,
                       idx, class_name, class_info, target_class, target_function,
                       class_alias_list):
        file_name = class_info['FILE_NAME']
        _file = self.target_file_list[file_name]
        _class = _file.class_list[class_name]

        key = '{}::{}'.format(file_name, class_name)
        parent_classes = self.class_hierarchy[key]['PARENTS']
        implements = self.class_hierarchy[key]['IMPLEMENTS']

        iter_flag = False
        for parent in parent_classes:
            if parent['NAME'] == 'ArrayIterator':
                iter_flag = True

        for implement in implements:
            if implement['NAME'] == 'Iterator':
                iter_flag = True

        access_flag = False
        for implement in implements:
            if implement['NAME'] == 'ArrayAccess':
                access_flag = True

        # print('[*] Class {}/{}'.format(idx, len(target_class)))
        # print('  [+] File: {}'.format(file_name))
        # print('  [+] Class: {}'.format(class_name))
        # print('  [+] Method: {}'.format(len(_class.method_list)))

        for method_name, method_info in _class.method_list.items():
            real_file_name = method_info.real_file
            real_class_name = method_info.real_class
            real_method_name = method_info.real_name
            if class_name in class_alias_list:
                class_alias = class_alias_list[class_name]
            else:
                class_alias = ''

            method = {'file': file_name,
                      'real_file': real_file_name,
                      'class': class_name,
                      'real_class': real_class_name,
                      'class_alias': class_alias,
                      'method': method_name,
                      'real_method': real_method_name}
            callees, func_callees = self._get_callees(method, target_class,
                                                      target_function, class_alias_list)

            method_list[json.dumps(method)] = {'INFO': method,
                                               'CALLEES': callees,
                                               'FUNC_CALLEES': func_callees}

            if method_name in MAGIC_METHODS:
                magic_method_list[method_name][json.dumps(method)] = {
                                                            'INFO': method,
                                                            'CALLEES': callees,
                                                            'FUNC_CALLEES': func_callees}

            if iter_flag:
                if method_name == 'current':
                    iter_list[json.dumps(method)] = {'INFO': method,
                                                     'CALLEES': callees,
                                                     'FUNC_CALLEES': func_callees}

            if access_flag:
                if method_name == 'offsetGet':
                    access_list[json.dumps(method)] = {'INFO': method,
                                                       'CALLEES': callees,
                                                       'FUNC_CALLEES': func_callees}

    def _get_all_methods(self, target_class, target_function, class_alias_list, cpus):
        method_list = self.manager.dict()
        magic_method_list = self.manager.dict()
        for magic_method in MAGIC_METHODS:
            magic_method_list[magic_method] = self.manager.dict()
        iter_list = self.manager.dict()
        access_list = self.manager.dict()
        proc_list = []

        for idx, (func_name, func_info) in enumerate(target_function.items()):
            proc = Process(target=self._process_function,
                           name='func{}'.format(idx),
                           args=(method_list, idx, func_name, func_info,
                           target_class, target_function, class_alias_list,))
            proc_list.append(proc)

        for idx, (class_name, class_info) in enumerate(target_class.items()):
            proc = Process(target=self._process_class,
                           name='class{}'.format(idx),
                           args=(method_list, magic_method_list, iter_list, access_list,
                           idx, class_name, class_info, target_class, target_function,
                           class_alias_list,))
            proc_list.append(proc)

        MAX_PROCESS = cpus
        running_proc = []
        finished_list = []
        tmp_per = 0
        while len(finished_list) != len(proc_list):
            per = round(len(finished_list)/float(len(proc_list))*100, 2)
            if tmp_per != per:
                print("[+] Method analysis - Finished: {}/{} ({} %)".format(
                      len(finished_list), len(proc_list), per))
                tmp_per = per
            for proc in proc_list:
                if proc.name not in running_proc and \
                   proc.name not in finished_list and \
                   len(running_proc) < MAX_PROCESS:
                    proc.start()
                    running_proc.append(proc.name)
                elif proc.name in running_proc and \
                     proc.name not in finished_list:
                    if not proc.is_alive():
                        finished_list.append(proc.name)
                        running_proc.remove(proc.name)
                elif proc.name in running_proc and \
                     proc.name in finished_list:
                    proc.terminate()
                    running_proc.remove(proc.name)

        return method_list, magic_method_list, iter_list, access_list

    def _get_param_cnt_range(self, param_list):
        min_cnt = len(param_list)
        max_cnt = len(param_list)
        default_list = []
        for param, default in sorted(param_list.items(),
                                     key=lambda x: (x[1]['INDEX'])):
            if default['DEFAULT'] is None:
                default_list.append(False)
            else:
                default_list.append(True)

        for default in default_list[::-1]:
            if default:
                min_cnt -= 1
            else:
                break
        return range(min_cnt, max_cnt+1)

    def _find_func(self, target_function, callee_name, args, arg_cnt,
                   depth, idx, this):
        func_list = []
        intenral_func_list = []

        # Traverse target functions
        for func_name, func_info in target_function.items():
            file_name = func_info['FILE_NAME']

            _file = self.target_file_list[file_name]
            _func = _file.func_list[func_name]
            param_cnt_range = self._get_param_cnt_range(_func.param_list)
            if func_name == callee_name and arg_cnt in param_cnt_range:
                func_list.append({'DATA': {'file': file_name,
                                           'real_file': file_name,
                                           'class': '',
                                           'real_class': '',
                                           'class_alias': '',
                                           'method': func_name,
                                           'real_method': func_name},
                                  'POS': {'depth': depth,
                                          'idx': idx,
                                          'this': this},
                                  'ARGS': args,
                                  'PARENTS': []})

        for func_name in IMPLICIT_CALLS:
            if func_name == callee_name:
                intenral_func_list.append({'DATA': {'file': INTERNAL,
                                                    'real_file': INTERNAL,
                                                    'class': '',
                                                    'real_class': '',
                                                    'class_alias': '',
                                                    'method': func_name,
                                                    'real_method': func_name},
                                           'POS': {'depth': depth,
                                                    'idx': idx,
                                                    'this': this},
                                           'ARGS': args,
                                           'PARENTS': []})

        return func_list, intenral_func_list

    def _find_method(self, target_class, class_alias_list, callee_name, args, arg_cnt,
                     depth, idx, this, static_class = ''):
        method_list = []

        # Traverse target classes
        for class_name, class_info in target_class.items():
            if static_class != '':
                if class_name != static_class:
                    continue

            file_name = class_info['FILE_NAME']
            class_type = class_info['CLASS_TYPE']
            if INTERFACE in class_type:
                continue
            _file = self.target_file_list[file_name]
            _class = _file.class_list[class_name]
            if class_name in class_alias_list:
                class_alias = class_alias_list[class_name]
            else:
                class_alias = ''

            for method_name, method_info in _class.method_list.items():
                param_cnt_range = self._get_param_cnt_range(
                                                        method_info.param_list)
                if method_name == callee_name and arg_cnt in param_cnt_range:
                    if method_info.real_file == INTERNAL:
                        continue

                    method_list.append({'DATA':
                                        {'file': file_name,
                                         'real_file': method_info.real_file,
                                         'class': class_name,
                                         'real_class': method_info.real_class,
                                         'class_alias': class_alias,
                                         'method': method_name,
                                         'real_method': method_info.real_name},
                                        'POS': {'depth': depth,
                                                'idx': idx,
                                                'this': this},
                                        'ARGS': args,
                                        'PARENTS': self._get_parents(
                                                   file_name, class_name)})
        return method_list

    def _find_parent_func(self, target_class, class_alias_list, file_name,
                          class_name, func_name, args, arg_cnt,
                          depth, idx, this):
        func_candidates = []
        key = '{}::{}'.format(file_name, class_name)
        parent_classes = self.class_hierarchy[key]['PARENTS']
        # print ('CLASS: {}, METHOD: {}'.format(class_name, func_name))
        for parent in parent_classes:
            func_candidates = self._find_method(target_class,
                                                class_alias_list,
                                                func_name,
                                                args,
                                                arg_cnt,
                                                depth,
                                                idx,
                                                this,
                                                static_class=parent['NAME'])
            # print ('PARENT: {} - {}'.format(parent, func_candidates))
            if len(func_candidates) > 0:
                return func_candidates
        return func_candidates

    def filter_abstract_class(self, chain):
        for gadget in chain[-2::-1]:
            file_name = gadget['file']
            class_name = gadget['class']

            _file = self.target_file_list[file_name]
            if class_name == '':
                continue
            else:
                _class = _file.class_list[class_name]
                if ABSTRACT in _class.type:
                    return False
        return True

    def analyze_data_flow(self, chain):
        arg_list = chain[-1]['args']

        for idx, gadget in enumerate(chain[-2::-1]):
            # print ('[{}] ARG_LIST: {}'.format(idx, arg_list))
            new_arg_list = []
            file_name = gadget['real_file']
            class_name = gadget['real_class']
            method_name = gadget['real_method']
            args = gadget['args']
            gadget_idx = chain[len(chain)-idx-1]['idx']

            _file = self.target_file_list[file_name]
            if class_name == '':
                _func = _file.func_list[method_name]
                call_list = _func.call_list
                taint_list = _func.taint_list
                param_list = _func.param_list
            else:
                _class = _file.class_list[class_name]
                _method = _class.method_list[method_name]
                call_list = _method.call_list
                taint_list = _method.taint_list
                param_list = _method.param_list

            # print (' [-] TAINT_LIST: {}'.format(taint_list))
            # print (' [-] PARAM_LIST: {}'.format(param_list))
            # print (' [-] ARGS: {}'.format(args))

            taint_flag = False
            for arg in arg_list:
                if arg is not None:
                    for taint_var, taint_info_list in taint_list.items():
                        if taint_var in arg:
                            # print ('  [-] ARG : {}'.format(arg))
                            taint_flag = True
                            for taint_info in taint_info_list:
                                taint_idx = taint_info['IDX']
                                taint_type = taint_info['TYPE']
                                root_taint = taint_info['ROOT']
                                # print ('  [-] TAINT: {}/{}/{}'.format(taint_idx, taint_type, root_taint))
                                if taint_idx < gadget_idx:
                                    if taint_type == 'PROP':
                                        # print ('TYPE: PROP')
                                        return True
                                    elif taint_type == 'ARG':
                                        # print ('TYPE: ARG')
                                        # print ('  [-] {} ROOT: {}'.format(arg, root_taint))
                                        # print ('  [-] PARAM: {}'.format(param_list))
                                        if idx == len(chain[-2::-1]) - 1:
                                            return True
                                        if root_taint not in new_arg_list:
                                            param = param_list[root_taint]
                                            param_idx = param['INDEX']
                                            # print ('  [-] PARAM IDX: {}'.format(param_idx))
                                            if param_idx != -1 and \
                                               param_idx < len(args):
                                                new_arg_list.append(args[param_idx])
                                            elif param_idx == -1:
                                                print ('!!!!!!!!!!!!!!!')

            if taint_flag == False:
                return False
            else:
                arg_list = new_arg_list

        return False

    def write_chain(self, analyzer, fuzz_dir, proc_id, chain, target_class):
        total_depth = 0
        total_idx = 0
        total_no_this = len(chain)
        for gadget in chain:
            total_depth += gadget['depth']
            total_idx += gadget['idx']
            if gadget['this']:
                total_no_this -= 1
        self.lock.acquire()
        fuzz_filepath = fuzz_dir + 'proc{}_{}_{}_{}_{}_{}.chain'.format(
            proc_id, self.chain_cnt[proc_id],
            len(chain), total_depth, total_idx, total_no_this)
        self.lock.release()
        with open(fuzz_filepath, 'w') as f:
            chain_info = analyzer.get_variable(chain, target_class)
            chain_info = analyzer.analyze_chain(chain_info)
            fuzz_code = analyzer.get_code(fuzz_dir, chain, chain_info)
            f.write(fuzz_code)
            f.write('\n')
        msg = {}
        msg['file_path'] = fuzz_filepath
        msg['proc_id'] = proc_id
        self.lock.acquire()
        msg['chain_id'] = self.chain_cnt[proc_id]
        self.lock.release()
        msg['chain_len'] = len(chain)
        msg['depth'] = total_depth
        msg['idx'] = total_idx
        msg['no_this'] = total_no_this
        channel = self._init_rabbitmq(self.send_queue, delete=False)
        channel.basic_publish(exchange='',
                              routing_key=self.send_queue,
                              body=json.dumps(msg))
        # print ("Write chain: proc{}, {} ({})".format(proc_id,
        #                                             self.chain_cnt[proc_id],
        #                                             fuzz_filepath))

    def _get_callees(self, caller, target_class, target_function, class_alias_list):
        callee_list = []
        callee_func_list = []

        file_name = caller['file']
        real_file_name = caller['real_file']
        class_name = caller['class']
        real_class_name = caller['real_class']
        caller_name = caller['method']
        real_caller_name = caller['real_method']

        if real_file_name == INTERNAL or real_file_name == False:
            return callee_list, callee_func_list

        _file = self.target_file_list[real_file_name]
        if real_class_name == '':
            _func = _file.func_list[caller_name]
            call_list = _func.call_list
            taint_list = _func.taint_list
            traits = []
        else:
            _class = _file.class_list[real_class_name]
            _method = _class.method_list[real_caller_name]
            call_list = _method.call_list
            taint_list = _method.taint_list
            traits = _class.traits

        alias_list = {}
        for trait in traits:
            for adaption in trait['ADAPTIONS']:
                if adaption['TYPE'] == 'ALIAS':
                    if adaption['NEW_NAME'] is not None:
                        alias_list[adaption['NEW_NAME']] = adaption['METHOD']

        var_list = {}

        call_set = []
        for call in call_list:
            if call in call_set:
                continue

            func_type = call['TYPE']
            func_name = call['FUNCTION']
            args = call['ARGS']
            depth = call['DEPTH']
            idx = call['IDX']
            this = call['THIS']

            if func_name in alias_list:
                func_name = alias_list[func_name]

            if call['ARGS'] is not None:
                arg_cnt = len(call['ARGS'])
            else:
                arg_cnt = 0

            if func_type == "New":
                var_name = call["VAR"]
                var_list[var_name] = func_name  # class name
                continue

            elif func_type == "FuncCall":
                taint_flag = False
                for arg in args:
                    if arg is not None:
                        for taint in taint_list:
                            if taint in arg:
                                taint_flag = True
                if taint_flag:
                    func_list, intenral_func_list = self._find_func(target_function,
                                                                    func_name, args,
                                                                    arg_cnt, depth,
                                                                    idx, this)
                    callee_list += func_list
                    callee_func_list += intenral_func_list
                else:
                    continue

            elif func_type == "MethodCall":
                func_class = call["CLASS"]
                if func_class == '$this':
                    if class_name == '':
                        continue
                    else:
                        callee_list += self._find_parent_func(target_class,
                                                              class_alias_list,
                                                              file_name,
                                                              class_name,
                                                              func_name,
                                                              args,
                                                              arg_cnt,
                                                              depth,
                                                              idx,
                                                              this)

                elif func_class in var_list.keys():
                    # $a = new A; $a->func();
                    if func_class not in taint_list:
                        continue
                    else:
                        callee_list += self._find_method(target_class,
                                                         class_alias_list,
                                                         func_name,
                                                         args,
                                                         arg_cnt,
                                                         depth,
                                                         idx,
                                                         this,
                                                         static_class = \
                                                         var_list[func_class])

                elif '::' in func_class:
                    # CLS::func()->func();
                    continue

                elif func_class in taint_list:
                    callee_list += self._find_method(target_class,
                                                     class_alias_list,
                                                     func_name,
                                                     args,
                                                     arg_cnt,
                                                     depth,
                                                     idx,
                                                     this)

                else:
                    taint_flag = False
                    for arg in args:
                        if arg is not None:
                            for taint in taint_list:
                                if taint in arg:
                                    taint_flag = True
                    if taint_flag:
                        callee_list += self._find_method(target_class,
                                                         class_alias_list,
                                                         func_name,
                                                         args,
                                                         arg_cnt,
                                                         depth,
                                                         idx,
                                                         this)
                    else:
                        continue

            elif func_type == "StaticCall":
                func_class = call["CLASS"]
                if func_class == 'parent':
                    if class_name == '':
                        continue
                    else:
                        callee_list += self._find_parent_func(target_class,
                                                              class_alias_list,
                                                              file_name,
                                                              class_name,
                                                              func_name,
                                                              args,
                                                              arg_cnt,
                                                              depth,
                                                              idx,
                                                              this)

                elif func_class == 'self':
                    if class_name == '':
                        continue
                    else:
                        callee_list += self._find_parent_func(target_class,
                                                              class_alias_list,
                                                              file_name,
                                                              class_name,
                                                              func_name,
                                                              args,
                                                              arg_cnt,
                                                              depth,
                                                              idx,
                                                              this)

                else:
                    continue

            call_set.append(call)

        return callee_list, callee_func_list

    def _find_call_chain(self, analyzer, fuzz_dir, target_class,
                         target_function, all_method_list, all_magic_method_list,
                         all_iter_list, all_access_list, class_alias_list,
                         available_magic_method_list, sink_caller,
                         sink, proc_id):
        call_chain_list = []
        call_chain_map = {}
        for method_hash in all_method_list:
            call_chain_map[method_hash] = [ {'value': 0, 'callee': []}
                                            for y in range(self.max_chain_len) ]

        for depth in range(self.max_chain_len):
            all_zero = True
            for method_hash, method in all_method_list.items():
                if depth == 0:
                    if sink_caller == method['INFO']:
                        call_chain_map[method_hash][depth]['value'] = 1
                        call_chain_map[method_hash][depth]['callee'].append(sink)

                else:
                    for callee in method['CALLEES']:
                        if method['INFO'] == callee['DATA']:
                            continue
                        callee_hash = json.dumps(callee['DATA'])
                        if call_chain_map[callee_hash][depth-1]['value'] > 0:
                            call_chain_map[method_hash][depth]['value'] = \
                              call_chain_map[callee_hash][depth-1]['value'] + 1
                            if callee not in \
                               call_chain_map[method_hash][depth]['callee']:
                                call_chain_map[method_hash][depth]['callee'].append(callee)

                    # Implicit call
                    for func_callee in method['FUNC_CALLEES']:
                        func_name = func_callee['DATA']['method']
                        for magic_method in IMPLICIT_CALLS[func_name]:
                            for m_hash, m in all_magic_method_list[magic_method].items():
                                if call_chain_map[m_hash][depth-1]['value'] > 0:
                                    call_chain_map[method_hash][depth]['value'] = \
                                      call_chain_map[m_hash][depth-1]['value'] + 1
                                    caller_data = {
                                        'DATA': {'file': m['INFO']['file'],
                                                 'real_file': m['INFO']['real_file'],
                                                 'class': m['INFO']['class'],
                                                 'real_class': m['INFO']['real_class'],
                                                 'class_alias': m['INFO']['class_alias'],
                                                 'method': m['INFO']['method'],
                                                 'real_method': m['INFO']['real_method']},
                                        'POS': {'depth': 0, 'idx': 0, 'this': 0},
                                        'ARGS': func_callee['ARGS'],
                                        'PARENTS': self._get_parents(m['INFO']['file'],
                                                                     m['INFO']['class']),
                                        'IMPLICIT': func_callee}
                                    if caller_data not in \
                                       call_chain_map[method_hash][depth]['callee']:
                                        call_chain_map[method_hash][depth]['callee'].append(caller_data)

                    file_name = method['INFO']['real_file']
                    class_name = method['INFO']['real_class']
                    method_name = method['INFO']['real_method']

                    if file_name:
                        _file = self.target_file_list[file_name]

                        if class_name == '':
                            _method = _file.func_list[method_name]

                        else:
                            _class = _file.class_list[class_name]
                            _method = _class.method_list[method_name]

                        for_list = _method.for_list
                        taint_list = _method.taint_list
                        array_access_list = _method.array_access_list
                        string_list = _method.string_list

                        # Foreach - Iterator::current
                        for expr in for_list.keys():
                            if expr.startswith('$this->') and expr in taint_list:
                                for m_hash, m in all_iter_list.items():
                                    if call_chain_map[m_hash][depth-1]['value'] > 0:
                                        call_chain_map[method_hash][depth]['value'] = \
                                          call_chain_map[m_hash][depth-1]['value'] + 1
                                        caller_data = {
                                        'DATA': {'file': m['INFO']['file'],
                                                 'real_file': m['INFO']['real_file'],
                                                 'class': m['INFO']['class'],
                                                 'real_class': m['INFO']['real_class'],
                                                 'class_alias': m['INFO']['class_alias'],
                                                 'method': m['INFO']['method'],
                                                 'real_method': m['INFO']['real_method']},
                                        'POS': {'depth': 0, 'idx': 0, 'this': 0},
                                        'ARGS': [],
                                        'PARENTS': self._get_parents(m['INFO']['file'],
                                                                     m['INFO']['class']),
                                        'FOREACH': {'expr': expr,
                                                    'file': m['INFO']['file'],
                                                    'class': m['INFO']['class']}}
                                        if caller_data not in \
                                           call_chain_map[method_hash][depth]['callee']:
                                            call_chain_map[method_hash][depth]['callee'].append(caller_data)

                        # ArrayDimFetch - ArrayAccess::offsetGet
                        for var, dim in array_access_list.items():
                            if var in taint_list and dim in taint_list:
                                for m_hash, m in all_access_list.items():
                                    if call_chain_map[m_hash][depth-1]['value'] > 0:
                                        call_chain_map[method_hash][depth]['value'] = \
                                          call_chain_map[m_hash][depth-1]['value'] + 1
                                        caller_data = {
                                        'DATA': {'file': m['INFO']['file'],
                                                 'real_file': m['INFO']['real_file'],
                                                 'class': m['INFO']['class'],
                                                 'real_class': m['INFO']['real_class'],
                                                 'class_alias': m['INFO']['class_alias'],
                                                 'method': m['INFO']['method'],
                                                 'real_method': m['INFO']['real_method']},
                                        'POS': {'depth': 0, 'idx': 0, 'this': 0},
                                        'ARGS': [],
                                        'PARENTS': self._get_parents(m['INFO']['file'],
                                                                     m['INFO']['class']),
                                        'ARRAY_ACCESS': {'expr': var,
                                                         'file': m['INFO']['file'],
                                                         'class': m['INFO']['class']}}
                                        if caller_data not in \
                                           call_chain_map[method_hash][depth]['callee']:
                                            call_chain_map[method_hash][depth]['callee'].append(caller_data)

                        # String - __toString
                        # for var in string_list:
                        #     if var in taint_list:
                        #         toString_dict = all_magic_method_list['__toString']
                        #         for m_hash, m in toString_dict.items():
                        #             if call_chain_map[m_hash][depth-1]['value'] > 0:
                        #                 call_chain_map[method_hash][depth]['value'] = \
                        #                   call_chain_map[m_hash][depth-1]['value'] + 1
                        #                 caller_data = {
                        #                 'DATA': {'file': m['INFO']['file'],
                        #                          'real_file': m['INFO']['real_file'],
                        #                          'class': m['INFO']['class'],
                        #                          'real_class': m['INFO']['real_class'],
                        #                          'method': m['INFO']['method'],
                        #                          'real_method': m['INFO']['real_method']},
                        #                 'POS': {'depth': 0, 'idx': 0, 'this': 0},
                        #                 'ARGS': [],
                        #                 'PARENTS': self._get_parents(m['INFO']['file'],
                        #                                              m['INFO']['class']),
                        #                 'STRING': {'expr': var,
                        #                            'file': m['INFO']['file'],
                        #                            'class': m['INFO']['class']}}
                        #                 if caller_data not in \
                        #                    call_chain_map[method_hash][depth]['callee']:
                        #                     call_chain_map[method_hash][depth]['callee'].append(caller_data)

                if call_chain_map[method_hash][depth]['value'] > 0:
                    all_zero = False
                    class_name = method['INFO']['class']
                    method_name = method['INFO']['method']

                    if class_name in target_class and \
                       method_name in available_magic_method_list:
                        method_info = copy.deepcopy(method['INFO'])
                        method_info['depth'] = 0
                        method_info['idx'] = 0
                        method_info['this'] = 0
                        method_info['args'] = []
                        method_info['parents'] = self._get_parents(
                                                    method_info['file'],
                                                    method_info['class'])
                        for call_chain in self._get_call_chains(all_method_list,
                                                                call_chain_map,
                                                                method_hash,
                                                                depth,
                                                                [method_info]):
                            if call_chain not in call_chain_list:
                                # print (call_chain)
                                if self.filter_abstract_class(call_chain) and \
                                   self.analyze_data_flow(call_chain):
                                    call_chain_list.append(call_chain)
                                    self.lock.acquire()
                                    self.chain_cnt[proc_id] += 1
                                    self.lock.release()
                                    self.write_chain(analyzer, fuzz_dir,
                                                    proc_id, call_chain,
                                                    target_class)

            if all_zero:
                break

        self.lock.acquire()
        self.new_chain_list[proc_id] = call_chain_list
        self.lock.release()
        return call_chain_list

    def _get_call_chains(self, all_method_list, call_chain_map,
                         method_hash, depth, call_chain):
        call_chain_list = []

        if depth == 0:
            method = call_chain_map[method_hash][depth]['callee'][0]
            method_info = copy.deepcopy(method['DATA'])
            method_info['depth'] = method['POS']['depth']
            method_info['idx'] = method['POS']['idx']
            method_info['this'] = method['POS']['this']
            method_info['order'] = method['POS']['order']
            method_info['parents'] = method['PARENTS']
            return [call_chain + [method_info]]

        else:
            for callee in call_chain_map[method_hash][depth]['callee']:
                new_call_chain = copy.deepcopy(call_chain)
                callee_hash = json.dumps(callee['DATA'])
                callee_info = copy.deepcopy(callee['DATA'])
                callee_info['depth'] = callee['POS']['depth']
                callee_info['idx'] = callee['POS']['idx']
                callee_info['this'] = callee['POS']['this']
                callee_info['parents'] = callee['PARENTS']
                if 'args' not in callee_info:
                    callee_info['args'] = callee['ARGS']
                if 'FOREACH' in callee:
                    new_call_chain[-1]['foreach'] = callee['FOREACH']
                if 'IMPLICIT' in callee:
                    new_call_chain[-1]['implicit'] = callee['IMPLICIT']
                if 'STRING' in callee:
                    new_call_chain[-1]['string'] = callee['STRING']
                if 'ARRAY_ACCESS' in callee:
                    new_call_chain[-1]['array_access'] = callee['ARRAY_ACCESS']
                call_chain_list += self._get_call_chains(all_method_list,
                                                         call_chain_map,
                                                         callee_hash, depth-1,
                                                         new_call_chain + \
                                                         [callee_info])

        return call_chain_list

    def _recv_fuzz_result(self, finished_list, proc_list):
        def cb(ch, method, properties, body):
            if body is not None:
                proc_id = body.decode('utf-8')
                print ('*** FINISH LIST LEN: {}'.format(len(finished_list)))
                if proc_id not in finished_list:
                    print ("*** FINISH: {}".format(proc_id))
                    finished_list.append(proc_id)

        while len(finished_list) != len(proc_list):
            try:
                channel = self._init_rabbitmq(self.recv_queue)
                channel.basic_consume(queue=self.recv_queue,
                                      on_message_callback=cb,
                                      auto_ack=True)
                channel.start_consuming()
            except pika.exceptions.StreamLostError:
                pass

    def find_chain(self, analyzer, fuzz_dir, rabbitmq_ip, target_dir,
                   class_hierarchy, target_class, target_function, class_alias,
                   available_magic_method_list, proxy, cpus):

        self.host = rabbitmq_ip
        self.class_hierarchy = class_hierarchy
        queue_name = target_dir.replace('/', '.')[1:]
        self.recv_queue = '{}_fuzz_result_channel'.format(queue_name)
        self.send_queue = '{}_fuzz_channel'.format(queue_name)

        chain_list = []
        proc_list = []
        class_alias_list = {}
        for original, alias in class_alias:
            class_alias_list[alias.lower()] = original.lower()
        sink_func_list = self._find_sink_functions(target_class,
                                                   target_function,
                                                   class_alias_list)
        total_len = len(sink_func_list)
        m1, m2, m3, m4 = self._get_all_methods(target_class,
                                               target_function,
                                               class_alias_list,
                                               cpus)
        all_method_list = copy.deepcopy(m1)
        all_magic_method_list = {}
        magic_method_cnt = 0
        for magic_method in MAGIC_METHODS:
            all_magic_method_list[magic_method] = copy.deepcopy(m2[magic_method])
            magic_method_cnt += len(all_magic_method_list[magic_method])
        all_iter_list = copy.deepcopy(m3)
        all_access_list = copy.deepcopy(m4)
        self.new_chain_list = self.manager.list(range(total_len))
        self.chain_cnt = self.manager.list(range(total_len))
        fuzz_schedule_proc, recv_chain_proc = proxy.run(total_len)

        print ("All method list: {}".format(len(all_method_list)))
        print ("All magic method list: {}".format(magic_method_cnt))
        print ("All current method list: {}".format(len(all_iter_list)))
        print ("All offsetGet method list: {}".format(len(all_access_list)))
        print ("All toString list: {}".format(len(all_magic_method_list['__toString'])))

        # for method_hash, method_info in all_method_list.items():
        #     print ('{}'.format(method_info['INFO']))

        for proc_id, sink in enumerate(sink_func_list):
            # print (proc_id, sink)
            self.chain_cnt[proc_id] = 0
            sink_caller = {'file': sink['DATA']['file'],
                            'real_file': sink['DATA']['real_file'],
                            'class': sink['DATA']['class'],
                            'real_class': sink['DATA']['real_class'],
                            'class_alias': sink['DATA']['class_alias'],
                            'method': sink['DATA']['method'],
                            'real_method': sink['DATA']['real_method']}
            proc = Process(target=self._find_call_chain,
                           name='proc{}'.format(proc_id),
                           args=(analyzer, fuzz_dir, target_class,
                           target_function, all_method_list, all_magic_method_list,
                           all_iter_list, all_access_list, class_alias_list,
                           available_magic_method_list,
                           sink_caller, sink, proc_id,))
            proc_list.append(proc)

        random.shuffle(proc_list)
        MAX_PROCESS = cpus/4*3
        running_proc = []
        finished_list = self.manager.list()
        recv_proc = Process(target=self._recv_fuzz_result, args=(finished_list,
                                                                 proc_list, ))
        recv_proc.start()
        while len(finished_list) != len(proc_list):
            # print ('[Chain Generator] Running List: {}'.format(running_proc))
            # print ('[Chain Generator] Finished List: {}'.format(finished_list))
            print("[+] Chain generation - Finished: {}/{} ({} %)".format(
                   len(finished_list),
                   len(proc_list),
                   round(len(finished_list)/float(len(proc_list))*100, 2)))
            for proc in proc_list:
                # Run X, Finish X -> Start
                if proc.name not in running_proc and \
                   proc.name not in finished_list and \
                   len(running_proc) < MAX_PROCESS:
                    proc.start()
                    running_proc.append(proc.name)

                # Run O, Finish X -> Check
                elif proc.name in running_proc and \
                     proc.name not in finished_list:
                    if not proc.is_alive():
                        finished_list.append(proc.name)
                        running_proc.remove(proc.name)

                # Run O, Finish O -> Fuzzer success
                # elif proc.name in running_proc and proc.name in finished_list:
                #    proc.terminate()
                #    running_proc.remove(proc.name)

                # Check timeout
                fuzz_schedule_proc.join(0.00001)
                if not fuzz_schedule_proc.is_alive():
                    recv_chain_proc.terminate()
                    recv_proc.terminate()
                    for p in proc_list:
                        if p.name in running_proc:
                            p.terminate()
                    return chain_list

            time.sleep(1)

        proxy.set_flag(cpus)
        while True:
            fuzz_schedule_proc.join(0.001)
            recv_chain_proc.join(0.001)
            if not fuzz_schedule_proc.is_alive():
                recv_chain_proc.join(0.01)
                recv_chain_proc.terminate()
                break
        recv_proc.terminate()

        # print ('chain_list length: {}'.format(len(new_chain_list)))
        # for i, proc_chain in enumerate(self.new_chain_list):
            # print ('{}: proc_chain: {} ({})'.format(i, proc_chain, len(proc_chain)))
            # for new_chain in proc_chain:
                # if new_chain not in chain_list:
                    # chain_list.append(new_chain)

        # print ("Total # of Chains: {}".format(len(chain_list)))
        return chain_list
