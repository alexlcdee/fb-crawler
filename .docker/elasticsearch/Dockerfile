FROM docker.elastic.co/elasticsearch/elasticsearch:5.6.3

ADD elasticsearch.yml /usr/share/elasticsearch/config/

USER root
RUN chown elasticsearch:elasticsearch config/elasticsearch.yml

RUN bin/elasticsearch-plugin install http://dl.bintray.com/content/imotov/elasticsearch-plugins/org/elasticsearch/elasticsearch-analysis-morphology/5.6.3/elasticsearch-analysis-morphology-5.6.3.zip

USER elasticsearch