<div class="panel">
    <div class="panel-heading"><i class="icon-info"></i> {l s='Redis Statistics' mod='ngs_redis'}</div>
    
    {if isset($stats.error)}
        <div class="alert alert-warning">
            {l s='Could not connect to Redis:' mod='ngs_redis'} {$stats.error}
        </div>
    {else}
        <div class="row">
            <div class="col-md-3">
                <div class="panel">
                    <div class="panel-heading">{l s='Version' mod='ngs_redis'}</div>
                    <div class="text-center"><h3>{$stats.version}</h3></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="panel">
                    <div class="panel-heading">{l s='Uptime' mod='ngs_redis'}</div>
                    <div class="text-center"><h3>{$stats.uptime}</h3></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="panel">
                    <div class="panel-heading">{l s='Used Memory' mod='ngs_redis'}</div>
                    <div class="text-center"><h3>{$stats.used_memory}</h3></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="panel">
                    <div class="panel-heading">{l s='Total Keys' mod='ngs_redis'}</div>
                    <div class="text-center"><h3>{$stats.keys}</h3></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="panel">
                    <div class="panel-heading">{l s='Hit Rate' mod='ngs_redis'}</div>
                    <div style="position: relative; height: 250px; width: 100%;">
                        <canvas id="hitRateChart"></canvas>
                    </div>
                    <div class="text-center" style="margin-top: 10px;">
                        <strong>{l s='Hit Rate:' mod='ngs_redis'} {$stats.hit_rate}%</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="panel">
                    <div class="panel-heading">{l s='Memory Fragmentation' mod='ngs_redis'}</div>
                    <div class="text-center">
                        <h3>{$stats.mem_fragmentation_ratio}</h3>
                        <p class="help-block">{l s='Ratio > 1.0 means fragmentation. Ratio < 1.0 means swapping.' mod='ngs_redis'}</p>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var ctx = document.getElementById('hitRateChart').getContext('2d');
                var hits = {$stats.hits|intval};
                var misses = {$stats.misses|intval};
                var total = hits + misses;

                var chartData = {
                    labels: ['Hits', 'Misses'],
                    datasets: [{
                        data: [hits, misses],
                        backgroundColor: [
                            'rgba(75, 192, 192, 0.2)',
                            'rgba(255, 99, 132, 0.2)'
                        ],
                        borderColor: [
                            'rgba(75, 192, 192, 1)',
                            'rgba(255, 99, 132, 1)'
                        ],
                        borderWidth: 1
                    }]
                };

                // Handle empty data case
                if (total === 0) {
                    chartData.labels = ['No Data'];
                    chartData.datasets[0].data = [1]; // Dummy value to render circle
                    chartData.datasets[0].backgroundColor = ['rgba(200, 200, 200, 0.2)'];
                    chartData.datasets[0].borderColor = ['rgba(200, 200, 200, 1)'];
                }

                var myChart = new Chart(ctx, {
                    type: 'doughnut',
                    data: chartData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        if (total === 0) return ' No Data available yet';
                                        var label = context.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        label += context.raw;
                                        return label;
                                    }
                                }
                            }
                        }
                    }
                });
            });
        </script>
    {/if}
</div>
