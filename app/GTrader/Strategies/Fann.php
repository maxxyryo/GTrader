<?php

namespace GTrader\Strategies;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use GTrader\Strategy;
use GTrader\Series;
use GTrader\Indicator;
use GTrader\Util;
use GTrader\Chart;
use GTrader\Exchange;
use GTrader\FannTraining;
use GTrader\Plot;

if (!extension_loaded('fann')) {
    throw new \Exception('FANN extension not loaded');
}

class Fann extends Strategy
{

    protected $_fann = null;                // fann resource
    protected $_data = [];
    protected $_sample_iterator = 0;
    protected $_callback_type = false;
    protected $_callback_iterator = 0;
    protected $_bias = null;


    public function __construct(array $params = [])
    {
        //error_log('Fann::__construct()');
        parent::__construct($params);
        $this->setParam('num_output', 1);
    }


    public function __wakeup()
    {
        if (defined('FANN_WAKEUP_PREFERRED_SUFFX')) {
            //error_log('Fann::__wakeup() Hacked path: '.$this->path().FANN_WAKEUP_PREFERRED_SUFFX);
            $this->loadOrCreateFann(FANN_WAKEUP_PREFERRED_SUFFX);
        } else {
            //error_log('Fann::__wakeup() path: '.$this->path());
            $this->loadOrCreateFann();
        }
    }


    public function toHTML(string $content = null)
    {
        return parent::toHTML(
            view('Strategies/'.$this->getShortClass().'Form', ['strategy' => $this])
        );
    }


    public function getTrainingChart()
    {
        $exchange = Exchange::getDefault('exchange');
        $symbol = Exchange::getDefault('symbol');
        $resolution = Exchange::getDefault('resolution');
        $mainchart = session('mainchart');
        if (is_object($mainchart)) {
            $exchange = $mainchart->getCandles()->getParam('exchange');
            $symbol = $mainchart->getCandles()->getParam('symbol');
            $resolution = $mainchart->getCandles()->getParam('resolution');
        }
        $candles = new Series([
            'limit' => 0,
            'exchange' => $exchange,
            'symbol' => $symbol,
            'resolution' => $resolution
        ]);
        $training_chart = Chart::make(null, [
            'candles' => $candles,
            'name' => 'trainingChart',
            'height' => 200,
            'disabled' => ['title', 'map', 'panZoom', 'strategy', 'settings']
        ]);
        $training_chart->saveToSession();
        return $training_chart;
    }


    public function getTrainingProgressChart(FannTraining $training)
    {
        $candles = new Series(['limit' => 0,
                                'exchange' => Exchange::getNameById($training->exchange_id),
                                'symbol' => Exchange::getSymbolNameById($training->symbol_id),
                                'resolution' => $training->resolution]);

        $highlights = [];
        foreach (['train', 'test', 'verify'] as $range) {
            if (isset($training->options[$range.'_start']) && isset($training->options[$range.'_end'])) {
                $highlights[] = [
                    'start' => $training->options[$range.'_start'],
                    'end' => $training->options[$range.'_end']
                ];
            }
        }

        $progress_chart = Chart::make(null, [
            'candles' => $candles,
            'strategy' => $this,
            'name' => 'trainingProgressChart',
            'height' => 200,
            'disabled' => ['title', 'strategy', 'map', 'settings'],
            'readonly' => ['esr'],
            'highlight' => $highlights,
            'visible_indicators' => ['Balance', 'Profitability']
        ]);

        if (!$progress_chart->hasIndicatorClass('Balance')) {
            $progress_chart->addIndicator('Balance');
            $this->save();
        }

        if (!$progress_chart->hasIndicatorClass('Profitability')) {
            $progress_chart->addIndicator('Profitability');
            $this->save();
        }

        $progress_chart->saveToSession();

        return $progress_chart;
    }


    public function getHistoryPlot(int $width, int $height)
    {
        $data = [];
        $items = DB::table('fann_history')
            ->select('epoch', 'name', 'value')
            ->where('strategy_id', $this->getParam('id'))
            ->orderBy('epoch', 'desc')
            ->orderBy('name', 'desc')
            ->limit(15000)
            ->get()
            ->reverse()
            ->values();
        foreach ($items as $item) {
            if (!array_key_exists($item->name, $data)) {
                $data[$item->name] = [];
            }
            $data[$item->name][$item->epoch] = $item->value;
        }
        ksort($data);
        $plot = new Plot([
            'name' => 'History',
            'width' => $width,
            'height' => $height,
            'data' => $data
        ]);
        return $plot->toHTML();
    }


    public function handleSaveRequest(Request $request)
    {
        $topology_changed = false;

        if (isset($request->hidden_array)) {
            $hidden_array = explode(',', $request->hidden_array);
            if (count($hidden_array)) {
                $request->hidden_array = [];
                foreach ($hidden_array as $hidden_layer) {
                    if (($hidden_layer = intval($hidden_layer)) && $hidden_layer > 0) {
                        $request->hidden_array[] = $hidden_layer;
                    }
                }
            }
            $current_hidden_array = $this->getParam('hidden_array');
            if (count($request->hidden_array) &&
                $current_hidden_array !== $request->hidden_array) {
                $topology_changed = true;
                $this->setParam('hidden_array', $request->hidden_array);
            }
        }

        $num_samples = $this->getParam('num_samples');
        if (isset($request->num_samples)) {
            $num_samples = intval($request->num_samples);
            if ($num_samples < 2) {
                $num_samples = 2;
            }
            if ($num_samples !== intval($this->getParam('num_samples'))) {
                $topology_changed = true;
            }
            $this->setNumSamples($num_samples);
        }

        $use_volume = 0;
        if (isset($request->use_volume)) {
            if (intval($request->use_volume)) {
                $use_volume = 1;
            }
        }
        if ($use_volume !== intval($this->getParam('use_volume'))) {
            $topology_changed = true;
        }
        $this->setParam('use_volume', $use_volume);

        if ($topology_changed) {
            error_log('Strategy '.$this->getParam('id').': topology changed, deleting fann.');
            $this->destroyFann();
            $this->deleteFiles();
        }

        foreach (['target_distance', 'long_threshold', 'short_threshold'] as $param) {
            if (isset($request->$param)) {
                $this->setParam($param, intval($request->$param));
            }
        }

        parent::handleSaveRequest($request);
        return $this;
    }


    public function listItem()
    {
        $training = FannTraining::select('status')
            ->where('strategy_id', $this->getParam('id'))
            ->where(function ($query) {
                $query->where('status', 'training')
                        ->orWhere('status', 'paused');
            })
            ->first();
        $training_status = null;
        if (is_object($training)) {
            $training_status = $training->status;
        }

        return view(
            'Strategies/FannListItem',
            [
                'strategy' => $this,
                'training_status' => $training_status
            ]
        );
    }


    public function getPredictionIndicator()
    {
        $class = $this->getParam('prediction_indicator_class');

        $indicator = null;
        foreach ($this->getIndicators() as $candidate) {
            if ($class === $candidate->getShortClass()) {
                $indicator = $candidate;
            }
        }
        if (is_null($indicator)) {
            $indicator = Indicator::make($class, ['display' => ['visible' => false]]);
            $this->addIndicator($indicator);
        }

        $ema_len = $this->getParam('prediction_ema');
        if ($ema_len > 1) {
            $candles = $this->getCandles();
            $indicator = Indicator::make(
                'Ema',
                ['indicator' => ['price' => $indicator->getSignature(), 'length' => $ema_len],
                 'display' => ['visible' => false],
                 'depends' => [$indicator]]
            );

            $candles->addIndicator($indicator);
            $indicator = $candles->getIndicator($indicator->getSignature());
        }

        return $indicator;
    }


    public function loadOrCreateFann(string $prefer_suffix = '')
    {
        if (is_resource($this->_fann)) {
            throw new \Exception('loadOrCreateFann called but _fann is already a resource');
        }

        // try first with suffix, if supplied
        if (strlen($prefer_suffix)) {
            $this->loadFann($this->path().$prefer_suffix);
        }

        // try without suffix
        if (!is_resource($this->_fann)) {
            $this->loadFann($this->path());
        }

        // create a new fann
        if (!is_resource($this->_fann)) {
            $this->createFann();
        }
        $this->initFann();
        return true;
    }


    public function loadFann($path)
    {
        if (is_file($path) && is_readable($path)) {
            //error_log('creating fann from '.$path);
            $this->_fann = fann_create_from_file($path);
            return true;
        }
        return false;
    }


    public function createFann()
    {
        //error_log('Fann::createFann() Input: '.$this->getNumInput());
        if ($this->getParam('fann_type') === 'fixed') {
            $params = array_merge(
                [$this->getNumLayers()],
                [$this->getNumInput()],
                $this->getParam('hidden_array'),
                [$this->getParam('num_output')]
            );
            //error_log('calling fann_create_shortcut('.join(', ', $params).')');
            //$this->_fann = call_user_func_array('fann_create_standard', $params);
            $this->_fann = call_user_func_array('fann_create_shortcut', $params);
        } elseif ($this->getParam('fann_type') === 'cascade') {
            $this->_fann = fann_create_shortcut(
                $this->getNumLayers(),
                $this->getNumInput(),
                $this->getParam('num_output')
            );
        } else {
            throw new \Exception('Unknown fann type');
        }
        $this->reset();
        return true;
    }


    public function reset()
    {
        fann_randomize_weights($this->_fann, -0.77, 0.77);
        return true;
    }


    public function initFann()
    {
        if (!is_resource($this->_fann)) {
            throw new \Exception('Cannot init fann, not a resource');
        }
        fann_set_activation_function_hidden($this->_fann, FANN_SIGMOID_SYMMETRIC);
        //fann_set_activation_function_output($this->_fann, FANN_SIGMOID_SYMMETRIC);
        //fann_set_activation_function_hidden($this->_fann, FANN_GAUSSIAN_SYMMETRIC);
        fann_set_activation_function_output($this->_fann, FANN_GAUSSIAN_SYMMETRIC);
        //fann_set_activation_function_hidden($this->_fann, FANN_LINEAR);
        //fann_set_activation_function_output($this->_fann, FANN_LINEAR);
        //fann_set_activation_function_hidden($this->_fann, FANN_ELLIOT_SYMMETRIC);
        //fann_set_activation_function_output($this->_fann, FANN_ELLIOT_SYMMETRIC);
        if ($this->getParam('fann_type') === 'fixed') {
            //fann_set_training_algorithm($this->_fann, FANN_TRAIN_INCREMENTAL);
            //fann_set_training_algorithm($this->_fann, FANN_TRAIN_BATCH);
            fann_set_training_algorithm($this->_fann, FANN_TRAIN_RPROP);
            //fann_set_training_algorithm($this->_fann, FANN_TRAIN_QUICKPROP);
            //fann_set_training_algorithm($this->_fann, FANN_TRAIN_SARPROP);
        }
        //fann_set_train_error_function($this->_fann, FANN_ERRORFUNC_LINEAR);
        fann_set_train_error_function($this->_fann, FANN_ERRORFUNC_TANH);
        //fann_set_learning_rate($this->_fann, 0.2);
        $this->_bias = null;
        return true;
    }


    public function getFann()
    {
        if (!is_resource($this->_fann)) {
            $this->loadOrCreateFann();
        }
        return $this->_fann;
    }


    public function copyFann()
    {
        return fann_copy($this->getFann());
    }


    public function setFann($fann)
    {
        if (!is_resource($fann)) {
            throw new \Exception('supplied fann is not a resource');
        }
        //error_log('setFann('.get_resource_type($fann).')');
        //var_dump(debug_backtrace());
        //if (is_resource($this->_fann)) $this->destroyFann(); // do not destroy, it may have a reference
        $this->_fann = $fann;
        $this->initFann();
        return true;
    }


    public function saveFann(string $suffix = '')
    {
        $fn = $this->path().$suffix;
        if (!fann_save($this->getFann(), $fn)) {
            error_log('saveFann to '.$fn.' failed');
            return false;
        }
        if (!chmod($fn, 0666)) {
            error_log('chmod of '.$fn.' failed');
            return false;
        }
        return true;
    }



    public function delete()
    {
        // remove trainings
        FannTraining::where('strategy_id', $this->getParam('id'))->delete();
        // remove files
        $this->deleteFiles();
        // remove training history
        $this->deleteHistory();
        // remove strategy
        return parent::delete();
    }


    public function deleteHistory()
    {
        $affected = DB::table('fann_history')
            ->where('strategy_id', $this->getParam('id'))
            ->delete();
        error_log('Fann::deleteHistory() '.$affected.' records deleted.');
        return $this;
    }


    public function saveHistory(int $epoch, string $name, float $value)
    {
        DB::table('fann_history')
            ->insert([
                'strategy_id' => $this->getParam('id'),
                'epoch' => $epoch,
                'name' => $name,
                'value' => $value,
            ]);
        return $this;
    }


    public function getHistoryNumRecords()
    {
        return DB::table('fann_history')
            ->where('strategy_id', $this->getParam('id'))
            ->count();
    }


    public function pruneHistory(int $nth = 2)
    {
        if ($nth < 2) {
            $nth = 2;
        }
        $epochs = DB::table('fann_history')
            ->select('epoch')
            ->distinct()
            ->where('strategy_id', $this->getParam('id'))
            ->get();
        $count = 1;
        $deleted = 0;
        foreach ($epochs as $epoch) {
            if ($count == $nth) {
                $deleted +=  DB::table('fann_history')
                    ->where('strategy_id', $this->getParam('id'))
                    ->where('epoch', $epoch->epoch)
                    ->delete();
            }
            $count ++;
            if ($count > $nth) {
                $count = 1;
            }
        }
        error_log($deleted.' history records deleted.');
        return $this;
    }


    public function getLastTrainingEpoch()
    {
        $res = DB::table('fann_history')
            ->select('epoch')
            ->where('strategy_id', $this->getParam('id'))
            ->orderBy('epoch', 'desc')
            ->limit(1)
            ->first();
        return intval($res->epoch);
    }


    public function deleteFiles()
    {
        $fann = $this->path();
        foreach ([
            $fann,
            $fann.'.train',
            storage_path('logs/'.$this->getParam('training_log_prefix').$this->getParam('id').'.log')
        ] as $file) {
            error_log('Checking to delete '.$file);
            if (is_file($file)) {
                if (!is_writable($file)) {
                    error_log($file.' not writable');
                    continue;
                }
                unlink($file);
            }
        }
        return $this;
    }


    public function destroyFann()
    {
        if (is_resource($this->_fann)) {
            return fann_destroy($this->_fann);
        }
        return true;
    }


    public function run($input, $ignore_bias = false)
    {
        try {
            $output = fann_run($this->getFann(), $input);
            if (!$ignore_bias) {
                $output[0] -= $this->getBias();
            }
            return $output[0];
        } catch (\Exception $e) {
            error_log('fann_run error: '.$e->getMessage()."\n".
                        ' Input: '.serialize($input));
            return null;
        }
    }


    public function getBias()
    {
        if (!$this->getParam('bias_compensation')) {
            return 0; // bias disabled
        }
        if (!is_null($this->_bias)) {
            return $this->_bias * $this->getParam('bias_compensation');
        }
        $this->_bias = fann_run($this->getFann(), array_fill(0, $this->getNumInput(), 0))[0];
        //error_log('bias: '.$this->_bias);
        return $this->_bias * $this->getParam('bias_compensation');
    }


    public function resetSample()
    {
        $this->_sample_iterator = 0;
        $this->nextSample(null, 'reset');
        return true;
    }


    public function nextSample($size = null, $reset = false)
    {
        static $___sample = [];

        if ($reset == 'reset') {
            $___sample = [];
            return true;
        }

        $candles = $this->getCandles();

        if (!$candles->size()) {
            return null;
        }

        if (!$size) {
            $size = $this->getParam('num_samples') + $this->getParam('target_distance');
        }

        while ($candle = $candles->byKey($this->_sample_iterator)) {
            $this->_sample_iterator++;
            $___sample[] = $candle;
            $current_size = count($___sample);
            if ($current_size <  $size) {
                continue;
            }
            if ($current_size == $size) {
                return $___sample;
            }
            if ($current_size >  $size) {
                array_shift($___sample);
                return $___sample;
            }
        }
        return null;
    }


    public function candlesToData($name, $force = false)
    {

        if (isset($this->_data[$name]) && !$force) {
            return true;
        }
        $data = [];
        $images = 0;
        $num_samples = $this->getParam('num_samples');
        $use_volume = $this->getParam('use_volume');

        $this->resetSample();
        while ($sample = $this->nextSample()) {
            if ($use_volume) {
                $volumes = [];
                for ($i = 0; $i < $num_samples - 1; $i++) {
                    $volumes[] = intval($sample[$i]->volume);
                }
            }
            $input = [];
            for ($i = 0; $i < $num_samples; $i++) {
                if ($i < $num_samples - 1) {
                    $input[] = floatval($sample[$i]->open);
                    $input[] = floatval($sample[$i]->high);
                    $input[] = floatval($sample[$i]->low);
                    $input[] = floatval($sample[$i]->close);
                    continue;
                }
                // we only care about the open price for the last input candle
                $input[] = floatval($sample[$i]->open);
                $last_ohlc4 = Series::ohlc4($sample[$i]);
            }
            $output = Series::ohlc4($sample[count($sample)-1]);
            //$img_data = join(',', $input).','.$output;
            //error_log($img_data);

            /*// Normalize both input and output to -1, 1
            $min = min(min($input), $output);
            $max = max(max($input), $output);
            foreach ($input as $k => $v) $input[$k] = series::normalize($v, $min, $max);
            $output = array(series::normalize($output, $min, $max));
            */

            /*// Normalize input to -0.5, 0.5, output to bandpass -1, 1
            //$io_factor = 2;
            // Normalize input to -0.1, 0.1, output to bandpass -1, 1
            $io_factor = 10;
            $min = min($input);
            $max = max($input);
            foreach ($input as $k => $v) $input[$k] = series::normalize($v, $min, $max, -1/$io_factor, 1/$io_factor);
            $output = series::normalize($output, $min, $max, -1/$io_factor, 1/$io_factor);
            if ($output > 1) $output = 1;
            else if ($output < -1) $output = -1;
            $output = array($output);
            */

            if ($use_volume) {
                // Normalize volumes to -1, 1
                $min = min($volumes);
                $max = max($volumes);
                foreach ($volumes as $k => $v) {
                    $volumes[$k] = Series::normalize($v, $min, $max);
                }
            }

            // Normalize input to -1, 1, output is delta of last input and output scaled
            $min = min($input);
            $max = max($input);
            foreach ($input as $k => $v) {
                $input[$k] = Series::normalize($v, $min, $max);
            }
            $delta = $output - $last_ohlc4;
            //error_log($delta);
            $output = $delta * 100 / $last_ohlc4 / $this->getParam('output_scaling');
            if ($output > 1) {
                $output = 1;
            } elseif ($output < -1) {
                $output = -1;
            }

            if ($use_volume) {
                $input = array_merge($volumes, $input);
            }

            $data[] = array('input'  => $input, 'output' => [$output]);

            /*
            $images++;
            $img_data = join(',', $input).','.$output[0];
            if ($images > 100) {
            $images = 0;
            echo '<img src="graph.php?d='.$img_data.'&amp;t='.round($output[0], 3).'" />';
            flush();
            }
            */
        }

        //dump($data);
        $this->_data[$name] = $data;
        return true;
    }


    public function test()
    {
        $this->candlesToData('test');
        $this->_callback_type = 'test';
        $this->_callback_iterator = 0;
        $test_data = fann_create_train_from_callback(
            count($this->_data['test']),
            $this->getNumInput(),
            $this->getParam('num_output'),
            array($this, 'createCallback')
        );

        $mse = fann_test_data($this->getFann(), $test_data);
        //$bit_fail = fann_get_bit_fail($this->getFann());
        //echo '<br />MSE: '.$mse.' Bit fail: '.$bit_fail.'<br />';
        return $mse;
    }


    public function train($max_epochs = 5000)
    {

        $t = time();
        $this->candlesToData('train'); //echo " DEBUG stop that train\n"; return false;
        $this->_callback_type = 'train';
        $this->_callback_iterator = 0;
        $training_data = fann_create_train_from_callback(
            count($this->_data['train']),
            $this->getNumInput(),
            $this->getParam('num_output'),
            array($this, 'createCallback')
        );
        //fann_save_train($training_data, BASE_PATH.'/fann/train.dat');

        $desired_error = 0.0000001;

        /* Fixed topology */
        $epochs_between_reports = 0;

        /* Cascade */
        $max_neurons = $max_epochs / 10;
        if ($max_neurons < 1) {
            $max_neurons = 1;
        }
        if ($max_neurons > 1000) {
            $max_neurons = 1000;
        }
        $neurons_between_reports = 0;

        //echo 'Training... '; flush();
        if ($this->getParam('fann_type') === 'fixed') {
            $res = fann_train_on_data(
                $this->getFann(),
                $training_data,
                $max_epochs,
                $epochs_between_reports,
                $desired_error
            );
        } elseif ($this->getParam('fann_type') === 'cascade') {
            $res = fann_cascadetrain_on_data(
                $this->getFann(),
                $training_data,
                $max_neurons,
                $neurons_between_reports,
                $desired_error
            );
        } else {
            throw new \Exception('Unknown fann type.');
        }

        $this->_bias = null;

        if ($res) {
            //echo 'done in '.(time()-$t).'s. Connections: '.count(fann_get_connection_array($this->getFann())).
            //      ', MSE: '.fann_get_MSE($this->getFann()).'<br />';
            return true;
        }
        return false;
    }


    public function createCallback($num_data, $num_input, $num_output)
    {

        if (!$this->_callback_type) {
            throw new \Exception('callback type not set');
        }

        //error_log('train callback: '.$num_data.' '.$num_input.' '.$num_output.' '.$this->_callback_iterator.' '.
        //      count($this->_data[$this->_callback_type]));
        $this->_callback_iterator++;
        return is_array($this->_data[$this->_callback_type][$this->_callback_iterator-1]) ?
            $this->_data[$this->_callback_type][$this->_callback_iterator-1] :
            false;
    }




    /** Mean Squared Error Reciprocal */
    public function getMSER()
    {
        $mse = fann_get_MSE($this->getFann());
        //error_log('MSE: '.$mse);
        if ($mse) {
            return 1 / $mse;
        }
        return 0;
    }


    public function getNumSamples()
    {
        return $this->getParam('num_samples');
    }


    public function setNumSamples(int $num)
    {
        $this->setParam('num_samples', $num);
        return $this;
    }


    public function getNumInput()
    {
        $fields = 4; // O, H, L, C
        if ($this->getParam('use_volume')) {
            $fields++;
        }
        // last sample has only open
        return ($this->getParam('num_samples') -1) * $fields + 1;
    }


    public function getNumLayers()
    {
        if ($this->getParam('fann_type') === 'fixed') {
            return count($this->getParam('hidden_array')) + 2;
        }
        if ($this->getParam('fann_type') === 'cascade') {
            return 2;
        }
        throw new \Exception('Unknown fann type');
    }


    public function path()
    {
        $dir = $this->getParam('path');
        if (!is_dir($dir)) {
            if (!mkdir($dir)) {
                throw new \Exception('Failed to create '.$dir);
            }
            if (!chmod($dir, 0775)) {
                throw new \Exception('Failed to chmod '.$dir);
            }
        }
        return $dir.DIRECTORY_SEPARATOR.$this->getParam('id').'.fann';
    }


    public function hasBeenTrained()
    {
        return is_file($this->path());
    }
}
