/*global define, require*/
/*jslint nomen: true*/
define([
    'jquery',
    'underscore',
    'orotranslation/js/translator',
    'oro/filter/datetime-filter',
    'oroentity/js/field-choice',
    'oroquerydesigner/js/field-condition'
], function($, _, __, DateTimeFilter) {
    'use strict';

    $.widget('oroauditquerydesigner.dataAuditCondition', $.oroquerydesigner.fieldCondition, {
        options: {
            changeStateTpl: _.template($('#template-audit-condition-type-select').html())
        },

        _create: function() {
            this._superApply(arguments);

            var data = this.element.data('value');
            if (data && data.columnName) {
                this.element.one('changed', _.bind(this._renderChangeStateChoice, this, data));
            } else {
                this.element.data('value', data);
            }

            this._on(this.$fieldChoice, {
                changed: function (e, fieldId) {
                    if (this.auditFilter) {
                        this.auditFilter.reset();
                    }
                    this._renderChangeStateChoice();
                }
            });
        },

        _renderChangeStateChoice: function (data) {
            if (this.$changeStateChoice) {
                return;
            }

            data = data || $.extend(true, {
                criterion: {
                    data: {
                        auditFilter: {
                            type: 'changed'
                        }
                    }
                }
            }, this.element.data('value'));

            this.$changeStateChoice = $(this.options.changeStateTpl({
                selected: data.criterion.data.auditFilter.type,
                changedLabel: __('oro.dataaudit.data_audit_condition.changed'),
                changedToValueLabel: __('oro.dataaudit.data_audit_condition.changed_to_value')
            }));

            var filterOptions = _.findWhere(this.options.filters, {
                type: 'datetime'
            });

            if (!filterOptions) {
                throw new Error('Cannot find filter "datetime"');
            }

            filterOptions.criteriaValueSelectors = {};
            $.extend(filterOptions.criteriaValueSelectors, DateTimeFilter.prototype.criteriaValueSelectors, {
                date_type: 'select[name=datetime]'
            });

            this.auditFilter = new (DateTimeFilter.extend(filterOptions))();
            this.auditFilter.value = data.criterion.data.auditFilter.data;
            this.auditFilter.on('update', _.bind(this._onUpdate, this));

            this.$fieldChoice.after($('<div class="active-filter">').html(this.auditFilter.render().$el));
            this.auditFilter.$el.find('.dropdown:first').after(this.$changeStateChoice);
            var $select = this.$changeStateChoice.find('select');
            this.$filterContainer.css('display', 'block');

            this._on(this.auditFilter.$el, {
                change: function () {
                    this.auditFilter.applyValue();
                }
            });
            this._on(this.$changeStateChoice, {
                change: function () {
                    this._onUpdate();
                }
            });

            var onChangeCb = {
                'changed': this._renderChangedChoice,
                'changed_to_value': this._renderChangedToValueChoice
            };
            onChangeCb[$select.val()].apply(this);

            $select.on('change', _.bind(function (e) {
                onChangeCb[$(e.currentTarget).val()].apply(this);
            }, this));

            this.element.data('value', data);
        },

        _renderChangedChoice: function () {
            this.$filterContainer.hide();
        },

        _renderChangedToValueChoice: function () {
            this.$filterContainer.show();
        },

        _getFilterCriterion: function () {
            var filter = {
                filter: this.filter.name,
                data: this.filter.getValue()
            };

            if (this.filter.filterParams) {
                filter.params = this.filter.filterParams;
            }

            var auditFilter = {};
            if (this.auditFilter) {
                auditFilter.columnName = this.element.find('input.select').select2('val');
                auditFilter.data = this.auditFilter.getValue();

                if (this.auditFilter.filterParams) {
                    auditFilter.params = this.auditFilter.filterParams;
                }
            }
            if (this.$changeStateChoice) {
                auditFilter.type = this.$changeStateChoice.find('select').val();
            }

            return {
                filter: 'audit',
                data: {
                    filter: filter,
                    auditFilter: auditFilter
                }
            };
        },

        _appendFilter: function() {
            var data = this.element.data('value');

            if (data && data.criterion && data.criterion.data.filter) {
                var fieldConditionData = $.extend(true, {
                    criterion: {
                        data: {
                            filter: {
                                columnName: data.columnName
                            }
                        }
                    }
                }, data);

                fieldConditionData.columnName = data.columnName;
                this.element.data('value', {
                    columnName: data.columnName,
                    criterion: fieldConditionData.criterion.data.filter
                });
            } else {
                this.element.data('value', {});
            }

            this._superApply(arguments);

            this.element.data('value', data);
        },

        _onUpdate: function () {
            if (!this.auditFilter || !this.auditFilter.value || this.auditFilter.isEmptyValue()) {
                return this._superApply(arguments);
            }

            var value = {
                columnName: this.element.find('input.select').select2('val'),
                criterion: this._getFilterCriterion()
            };

            this.element.data('value', value);
            this.element.trigger('changed');
        },

        _destroy: function () {
            this._superApply(arguments);
            if (this.auditFilter) {
                this.auditFilter.dispose();
                delete this.auditFilter;
            }
        }
    });

    return $;
});
