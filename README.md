# redis-cluster 
**Redis cluster with Docker Compose** 

Using Docker Compose to set up a Redis master-slave deployment with Sentinel.

## Prerequisite

Install [Docker][4] and [Docker Compose][3] in your testing environment.

## Docker Compose template of Redis cluster

There are following services in deployment:

* master: Redis master
* slave:  Redis slave
* sentinel: Redis Sentinel
* php: PHP FPM container with installed Redis extension

The sentinels are configured with "mymaster" instance name using the following properties:

```
sentinel monitor mymaster redis-master 6379 2
sentinel down-after-milliseconds mymaster 5000
sentinel parallel-syncs mymaster 1
sentinel failover-timeout mymaster 5000
```

The details could be found in sentinel/sentinel.conf

The default values of the environment variables for Sentinel are as following:

* SENTINEL_QUORUM: 2
* SENTINEL_DOWN_AFTER: 30000
* SENTINEL_FAILOVER: 180000

## Play with it

Build the sentinel Docker image

```
docker-compose build
```

Start the redis cluster

```
docker-compose up --detach --scale sentinel=3 --scale slave=2
```

Check the status of redis cluster

```
docker-compose ps
```

The result is 

```
          Name                        Command               State          Ports
---------------------------------------------------------------------------------------
redis-cluster_master_1     docker-entrypoint.sh redis ...   Up      6379/tcp
redis-cluster_php_1        docker-php-entrypoint php-fpm    Up      9000/tcp
redis-cluster_sentinel_1   sentinel-entrypoint.sh           Up      26379/tcp, 6379/tcp
redis-cluster_sentinel_2   sentinel-entrypoint.sh           Up      26379/tcp, 6379/tcp
redis-cluster_sentinel_3   sentinel-entrypoint.sh           Up      26379/tcp, 6379/tcp
redis-cluster_slave_1      docker-entrypoint.sh redis ...   Up      6379/tcp
redis-cluster_slave_2      docker-entrypoint.sh redis ...   Up      6379/tcp    
```

Execute the test scripts `./test.sh` to simulate stop and recover the Redis master.
You will see the master switched to slave automatically. 

Or, you can do the test manually to pause/unpause redis server through

```
docker pause rediscluster_master_1
docker unpause rediscluster_master_1
```

And get the sentinel information with following commands:

```
docker exec rediscluster_sentinel_1 redis-cli -p 26379 SENTINEL get-master-addr-by-name mymaster
```

## References

[https://github.com/mdevilliers/docker-rediscluster][1]

[https://registry.hub.docker.com/u/joshula/redis-sentinel/] [2]

[1]: https://github.com/mdevilliers/docker-rediscluster
[2]: https://registry.hub.docker.com/u/joshula/redis-sentinel/
[3]: https://docs.docker.com/compose/
[4]: https://www.docker.com

## License

Apache 2.0 license 

## Contributors

* Li Yi (<denverdino@gmail.com>)
* Ty Alexander (<ty.alexander@gmail.com>)

